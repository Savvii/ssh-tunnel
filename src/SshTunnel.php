<?php

/**
 * Partly inspired on https://github.com/stechstudio/laravel-ssh-tunnel/blob/master/src/Jobs/CreateTunnel.php
 */

declare(strict_types=1);

namespace Savvii\SshTunnel;

use ErrorException;

class SshTunnel
{
    /**
     * Port of the local side of the tunnel, can be automatically assigned.
     * You can find the local address in $localAddress.
     * @var int
     */
    public int $localPort;

    /**
     * The command for creating the tunnel
     * @var array<string>
     */
    protected array $sshCommand;

    /**
     * Command for checking if the tunnel is open
     * @var string
     */
    protected string $verifyCommand;

    /**
     * The command for checking local ports in use
     * @var string
     */
    protected string $lsofCommand;

    /**
     * This number is used to auto-assign local port numbers
     * @var int
     */
    protected static int $assignPort = 2049;

    /**
     * The SSH process opened using proc_open
     * @var array<resource|array<resource>>
     */
    protected array $sshProcess;

    /**
     * Constructor
     *
     * @param string $sshUsername
     * @param string $sshHost Can be a hostname or an IP address.
     * @param int $sshPort 22 by default
     * @param string $localAddress Address of the local side of the tunnel, 127.0.0.1 by default
     * @param int $localPort Port of the local side of the tunnel, leave empty to auto-assign.
     * @param string $bindHost Hostname or IP of the remote side of the tunnel. This can also be another server which
     *                         is reachable from inside the SSH Host. 127.0.0.1 by default (localhost on the SSH Host).
     * @param int $bindPort Port of the remote side of the tunnel, 3306 by default.
     * @param string $identityFile A file containing your private key. Empty by default.
     * @param int $waitMs Milliseconds to wait after connecting, 1000000 = 1 second by default.
     * @param int $tries Number of attempts to verify connection.
     * @param array<string> $sshOptions Extra SSH options, empty by default.
     * @param bool $autoConnect If true (default) the SSH tunnel will be connected on creation of this object.
     * @param bool $autoDisconnect If true (default) the SSH tunnel will be removed when this object is destroyed.
     * @param string $sshPath Path to 'ssh' binary.
     * @param string $lsofPath Path to 'lsof' binary, used to check if local port is available. Can be empty.
     * @param string $ncPath Path to 'nc' binary, used to verify the SSH tunnel is working. Can be empty.
     * @throws \ErrorException
     */
    public function __construct(
        string $sshUsername,
        string $sshHost,
        int $sshPort = 22,
        public string $localAddress = '127.0.0.1',
        int $localPort = 0,
        string $bindHost = '127.0.0.1',
        int $bindPort = 3306,
        string $identityFile = '',
        protected int $waitMs = 1000000,
        protected int $tries = 10,
        array $sshOptions = ['-q'],
        bool $autoConnect = true,
        protected bool $autoDisconnect = true,
        string $sshPath = 'ssh',
        string $lsofPath = 'lsof',
        string $ncPath = 'nc'
    ) {
        if (!empty($lsofPath)) {
            $this->lsofCommand = sprintf(
                '%s -P -n -i :%%d',
                escapeshellcmd($lsofPath)
            );
        }

        $this->localPort = $localPort;
        if (empty($this->localPort)) {
            do {
                $this->localPort = self::$assignPort++;
            } while ($this->localPort <= 65535 and false === $this->isLocalPortAvailable());
        }

        if (!empty($identityFile)) {
            $sshOptions = array_merge(
                $sshOptions,
                [
                    '-i',
                    $identityFile
                ]
            );
        }
        $this->sshCommand = array_merge(
            [
                $sshPath
            ],
            $sshOptions,
            [
                '-N',
                '-L',
                $this->localPort . ':' . $bindHost . ':' . $bindPort,
                '-p',
                $sshPort,
                $sshUsername . '@' . $sshHost
            ]
        );

        if (!empty($ncPath)) {
            $this->verifyCommand = sprintf(
                '%s -vz %s %d',
                escapeshellcmd($ncPath),
                escapeshellarg($localAddress),
                $this->localPort
            );
        }

        if ($autoConnect) {
            $this->connect();
        }
    }

    /**
     * Destructor. Disconnects SSH Tunnel.
     */
    public function __destruct()
    {
        if ($this->autoDisconnect) {
            $this->disconnect();
        }
    }

    /**
     * Check if the local port is available.
     * @return ?bool Null when there is no method to verify.
     * @throws \ErrorException
     */
    public function isLocalPortAvailable(): ?bool
    {
        if (empty($this->lsofCommand)) {
            return null;
        }
        return (1 === $this->runCommand(sprintf($this->lsofCommand, $this->localPort)));
    }

    /**
     * Connect SSH tunnel.
     * @return bool
     * @throws \ErrorException
     */
    public function connect(): bool
    {
        // Verify first. If there is already a working tunnel that's OK.
        if (true === $this->verifyTunnel()) {
            return true;
        }

        if (false === $this->isLocalPortAvailable()) {
            throw new ErrorException(
                sprintf(
                    "Local port %d is not available.\nVerified with: %s",
                    $this->localPort,
                    sprintf($this->lsofCommand, $this->localPort)
                )
            );
        }

        $this->sshProcess = $this->openProcess($this->sshCommand);

        // Ensure we wait long enough for it to actually connect.
        usleep($this->waitMs);

        for ($i = 0; $i < $this->tries; $i++) {
            if (false !== $this->verifyTunnel()) {
                return true;
            }
            // Wait a bit until next iteration
            usleep($this->waitMs);
        }

        throw new ErrorException(
            sprintf(
                "SSH tunnel is not working.\nCreated with: %s\nVerified with: %s",
                implode(' ', $this->sshCommand),
                $this->verifyCommand
            )
        );
    }

    /**
     * Disconnect SSH tunnel.
     * @return bool True if successful.
     */
    public function disconnect(): bool
    {
        if (!empty($this->sshProcess['proc'])) {
            $this->closeProcess($this->sshProcess, true);
            return true;
        }
        return false;
    }

    /**
     * Verifies whether the tunnel is active or not.
     * @return ?bool Null when there is no method to verify.
     * @throws \ErrorException
     */
    public function verifyTunnel(): ?bool
    {
        if (empty($this->verifyCommand)) {
            return null;
        }
        return (0 === $this->runCommand($this->verifyCommand, [2 => ['file', '/dev/null', 'w']]));
    }

    /**
     * @param string|array<string> $command
     * @param array<resource|array<string>> $descriptorSpec
     * @return array<resource|array<resource>>
     * @throws \ErrorException
     */
    protected function openProcess(
        string | array $command,
        array $descriptorSpec = []
    ): array {
        $result = [
            'pipes' => []
        ];
        $result['proc'] = proc_open(
            $command,
            $descriptorSpec,
            $result['pipes']
        );
        if (!is_resource($result['proc'])) {
            throw new ErrorException(sprintf("Error executing command: %s", $command));
        }
        $procStatus = proc_get_status($result['proc']);
        if (!$procStatus['running'] || !$procStatus['pid']) {
            throw new ErrorException(sprintf("Process is not running. Command: %s", $command));
        }
        return $result;
    }

    /**
     * Close a process opened by openProcess()
     * @param array<resource|array<resource>> $process
     * @param bool $kill
     * @return int
     */
    protected function closeProcess(array $process, bool $kill = false): int
    {
        foreach ($process['pipes'] as $pipe) {
            fclose($pipe);
        }
        if ($kill) {
            proc_terminate($process['proc']);
            proc_terminate($process['proc'], 9);
        }
        return proc_close($process['proc']);
    }

    /**
     * Runs a command, returns exit code.
     * @param string $command
     * @param array<resource|array<string>> $descriptorSpec
     * @return int 0 means Success.
     * @throws \ErrorException
     */
    protected function runCommand(
        string $command,
        array $descriptorSpec = []
    ): int {
        return $this->closeProcess($this->openProcess($command, $descriptorSpec));
    }
}
