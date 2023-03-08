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
     * @var string
     */
    protected string $sshCommand;

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
     * @var resource|false
     */
    protected $sshProcess;

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
     * @param string $verifyMethod Can be 'nc', 'bash' or empty to skip verify.
     * @param string $identityFile A file containing your private key. Empty by default.
     * @param int $waitMs Milliseconds to wait after connecting, 1000000 = 1 second by default.
     * @param int $tries Number of attempts to verify connection.
     * @param string $sshOptions Extra SSH options, empty by default.
     * @param string $logPath Where to send logging, /dev/null by default
     * @param bool $autoConnect If true (default) the SSH tunnel will be connected on creation of this object.
     * @param bool $autoDisconnect If true (default) the SSH tunnel will be removed when this object is destroyed.
     * @param string $sshPath Path to 'ssh' binary.
     * @param string $lsofPath Path to 'lsof' binary. Can be set to empty to skip check if local port is available.
     * @param string $ncPath Path to 'nc' binary.
     * @param string $bashPath Path to 'bash' binary.
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
        string $verifyMethod = 'bash',
        string $identityFile = '',
        protected int $waitMs = 1000000,
        protected int $tries = 10,
        string $sshOptions = '-q',
        string $logPath = '/dev/null',
        bool $autoConnect = true,
        protected bool $autoDisconnect = true,
        string $sshPath = 'ssh',
        string $lsofPath = 'lsof',
        string $ncPath = 'nc',
        string $bashPath = 'bash'
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
            $sshOptions .= ' -i ' . escapeshellarg($identityFile);
        }
        $this->sshCommand = sprintf(
            '%s %s -N -L %d:%s:%d -p %d %s@%s >> %s 2>&1',
            escapeshellcmd($sshPath),
            $sshOptions,
            $this->localPort,
            escapeshellarg($bindHost),
            $bindPort,
            $sshPort,
            escapeshellarg($sshUsername),
            escapeshellarg($sshHost),
            escapeshellarg($logPath)
        );

        $verifyCommands = [
            'nc' => sprintf(
                '%s -vz %s %d  > /dev/null 2>&1',
                escapeshellcmd($ncPath),
                escapeshellarg($localAddress),
                $this->localPort
            ),
            'bash' => sprintf(
                'timeout 1 %s -c %s > /dev/null 2>&1',
                escapeshellcmd($bashPath),
                escapeshellarg(
                    sprintf(
                        'cat < /dev/null > %s',
                        escapeshellarg(
                            sprintf('/dev/tcp/%s/%d', $localAddress, $this->localPort)
                        )
                    )
                )
            )
        ];
        if (!empty($verifyMethod)) {
            $this->verifyCommand = $verifyCommands[$verifyMethod];
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

        if (isset($this->lsofCommand)) {
            if (!$this->isLocalPortAvailable()) {
                throw new ErrorException(
                    sprintf(
                        "Local port %d is not available.\nVerified with: %s",
                        $this->localPort,
                        sprintf($this->lsofCommand, $this->localPort)
                    )
                );
            }
        }

        $this->sshProcess = $this->openProcess($this->sshCommand);
        $procDetails = proc_get_status($this->sshProcess);
        if (!$procDetails['running']) {
            throw new ErrorException(
                sprintf(
                    "SSH Tunnel process is not running after creation.\nCommand: %s",
                    $this->sshCommand
                )
            );
        }
        usleep($this->waitMs);

        for ($i = 0; $i < $this->tries; $i++) {
            if ($this->verifyTunnel()) {
                return true;
            }
            // Wait a bit until next iteration
            usleep($this->waitMs);
        }

        throw new ErrorException(
            sprintf(
                "SSH tunnel not working.\nCreated with: %s\nVerified with: %s",
                $this->sshCommand,
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
        if ($this->sshProcess) {
            proc_terminate($this->sshProcess);
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
        return (0 === $this->runCommand($this->verifyCommand));
    }

    /**
     * @param string $command
     * @return resource
     * @throws \ErrorException
     */
    protected function openProcess(string $command): mixed
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new ErrorException(sprintf("Error executing command: %s", $command));
        }
        return $process;
    }

    /**
     * Runs a command, returns exit code.
     * @param string $command
     * @return int 0 means Success.
     * @throws \ErrorException
     */
    protected function runCommand(string $command): int
    {
        return proc_close($this->openProcess($command));
    }
}
