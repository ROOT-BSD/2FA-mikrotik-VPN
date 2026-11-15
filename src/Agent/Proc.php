<?php

declare(strict_types=1);

namespace VpnGw\Agent;

/** Runs a command without a shell (argument list), optionally appending output to a log. */
final class Proc
{
    /**
     * @param list<string> $cmd
     * @param array<string, string>|null $env
     * @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr] (outputs empty when a log file is given)
     */
    public static function run(array $cmd, ?string $logFile = null, ?string $cwd = null, ?array $env = null): array
    {
        $errFile = $logFile ?? (string) tempnam(sys_get_temp_dir(), 'vpngw-err');
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => $logFile === null ? ['pipe', 'w'] : ['file', $logFile, 'a'],
            2 => ['file', $errFile, 'a'],
        ];
        $proc = proc_open($cmd, $spec, $pipes, $cwd, $env);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot start ' . $cmd[0]);
        }
        $output = '';
        $error = '';
        if ($logFile === null) {
            $output = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        $code = proc_close($proc);
        if ($logFile === null) {
            $error = (string) file_get_contents($errFile);
            @unlink($errFile);
        }
        return [$code, $output, $error];
    }

    /** @param list<string> $cmd */
    public static function mustRun(array $cmd, ?string $cwd = null): string
    {
        [$code, $out, $err] = self::run($cmd, null, $cwd);
        if ($code !== 0) {
            throw new \RuntimeException(sprintf('%s failed (exit %d): %s', implode(' ', $cmd), $code, trim(mb_substr($err . $out, 0, 500))));
        }
        return $out;
    }
}
