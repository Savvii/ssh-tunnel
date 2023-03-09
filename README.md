[![Code Quality](https://github.com/Savvii/ssh-tunnel/actions/workflows/codeQuality.yml/badge.svg)](https://github.com/Savvii/ssh-tunnel/actions/workflows/codeQuality.yml)

Small library to create a SSH Tunnel
---

Example usage:

```php
use Savvii\SshTunnel\SshTunnel;

$tunnel = new SshTunnel(
    sshUsername: 'myuser',
    sshHost: 'jumpserver.example.com',
    sshPort: 22,
    bindHost: 'remote-db-host.local.lan',
    bindPort: 3306    
);

$db = new PDO(
    sprintf(
        "mysql:host=%s;port=%d",
        $tunnel->localAddress,
        $tunnel->localPort
    )
);
```

When the PHP script ends or the `SshTunnel` object is destroyed the SSH tunnel is disconnected.

### Warning

When you create the object but let it go out of scope, by default the SSH tunnel will be cleaned up.
This will not work:

```php
function connect(): void
{
    $tunnel = new SshTunnel(...);
}

connect();
// At this point the SSH tunnel is disconnected because $tunnel went out of scope.
```

This will work:

```php
function connect(): void
{
    return new SshTunnel(...);
}

$tunnel = connect();
// At this point the SSH tunnel works.
```

If you are creating the `SshTunnel` object in the constructor of a class, make sure to store it in a class property,
to make it not go out of scope when the constructor is finished.

## Requirements

- Linux, MacOS or FreeBSD
- PHP 8.0 or greater
- PHP functions `proc_open`,`proc_close`,`proc_terminate` and `proc_get_status` enabled
- Binary `ssh`
- Binary `lsof`, used by default but can be skipped.
- Binary `nc`, used by default but can be skipped.
