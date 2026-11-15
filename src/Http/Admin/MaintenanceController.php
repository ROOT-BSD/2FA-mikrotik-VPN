<?php

declare(strict_types=1);

namespace VpnGw\Http\Admin;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;
use VpnGw\Admin\Jobs;
use VpnGw\Agent\ReleaseInspector;
use VpnGw\Config;
use VpnGw\Http\Session;
use VpnGw\Http\View;
use VpnGw\Log\AuditLogger;
use VpnGw\Version;

/** Backups (list, create, download), web update and job status pages. */
final class MaintenanceController extends AdminController
{
    public const MAX_UPLOAD = 50 * 1024 * 1024;

    public function __construct(
        Config $cfg,
        Session $session,
        View $view,
        ClockInterface $clock,
        AuditLogger $audit,
        private readonly Jobs $jobs,
    ) {
        parent::__construct($cfg, $session, $view, $clock, $audit);
    }

    // ---- backups -----------------------------------------------------------------

    public function backups(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response): Response {
            if ($request->getMethod() === 'POST') {
                if ($this->cfg->get('BACKUP_PASSPHRASE') === '') {
                    $this->flash('Спершу задайте пароль шифрування копій (Налаштування → Резервне копіювання).', 'error');
                } elseif ($this->jobs->busy('backup')) {
                    $this->flash('Резервне копіювання вже виконується.', 'warning');
                } else {
                    $id = Jobs::newId($this->clock->now());
                    $this->jobs->enqueue($id, 'backup', $admin, $this->clock->now());
                    $this->audit->event('admin_backup_requested', $admin, $this->remote($request), ['job' => $id]);
                    return $this->redirect($response, "/admin/jobs/$id");
                }
                return $this->redirect($response, '/admin/backups');
            }
            return $this->page($response, 'admin_backups', 'backups', [
                'backups' => $this->jobs->backups(),
                'jobs' => array_values(array_filter($this->jobs->list(30), static fn ($j) => $j['type'] === 'backup')),
                'enabled' => $this->cfg->bool('BACKUP_ENABLED'),
                'hour' => $this->cfg->int('BACKUP_HOUR'),
                'keep' => $this->cfg->int('BACKUP_KEEP'),
                'hasPassphrase' => $this->cfg->get('BACKUP_PASSPHRASE') !== '',
                'busy' => $this->jobs->busy('backup'),
                'backupDir' => $this->jobs->backupDir(),
            ]);
        });
    }

    public function download(Request $request, Response $response, string $name): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response, $name): Response {
            $path = $this->jobs->backupPath($name);
            $fh = $path === null ? false : fopen($path, 'rb');
            if ($fh === false) {
                return $response->withStatus(404);
            }
            $this->audit->event('admin_backup_download', $admin, $this->remote($request), ['file' => $name]);
            return $response
                ->withBody(new Stream($fh))
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
                ->withHeader('Content-Length', (string) filesize((string) $path));
        });
    }

    // ---- update ------------------------------------------------------------------

    public function update(Request $request, Response $response): Response
    {
        return $this->guarded($request, $response, function (string $admin) use ($request, $response): Response {
            if ($request->getMethod() !== 'POST') {
                return $this->updatePage($response);
            }
            try {
                $id = $this->acceptUpload($request, $admin);
            } catch (\RuntimeException $e) {
                $this->audit->event('admin_update_rejected', $admin, $this->remote($request), ['reason' => $e->getMessage()]);
                return $this->updatePage($response, ['error' => $e->getMessage()], 422);
            }
            return $this->redirect($response, "/admin/jobs/$id");
        });
    }

    /** Validates the upload, moves it to the spool and queues the job; returns the job id. */
    private function acceptUpload(Request $request, string $admin): string
    {
        if ($this->cfg->get('BACKUP_PASSPHRASE') === '') {
            throw new \RuntimeException('Перед оновленням створюється резервна копія — спершу задайте пароль шифрування копій.');
        }
        if ($this->jobs->busy('update')) {
            throw new \RuntimeException('Оновлення вже виконується.');
        }
        $files = $request->getUploadedFiles();
        $archive = $files['archive'] ?? null;
        if (!$archive instanceof UploadedFileInterface || $archive->getError() !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Виберіть файл архіву vpn-gw-X.Y.zip (або файл завеликий).');
        }
        $name = (string) $archive->getClientFilename();
        $version = ReleaseInspector::versionFromName($name);
        if ($version === null) {
            throw new \RuntimeException('Ім’я архіву має бути vpn-gw-X.Y.zip (як у релізі).');
        }
        $size = (int) $archive->getSize();
        if ($size < 100 || $size > self::MAX_UPLOAD) {
            throw new \RuntimeException('Розмір архіву неприпустимий.');
        }

        $checksumText = trim($this->field($request, 'sha256', 300));
        $sumFile = $files['checksum'] ?? null;
        if ($checksumText === '' && $sumFile instanceof UploadedFileInterface && $sumFile->getError() === UPLOAD_ERR_OK && (int) $sumFile->getSize() < 1024) {
            $checksumText = (string) $sumFile->getStream();
        }
        $parsed = ReleaseInspector::parseChecksum($checksumText);
        if ($parsed === null) {
            throw new \RuntimeException('Додайте файл vpn-gw-X.Y.zip.sha256 або вставте контрольну суму SHA-256.');
        }
        [$expected, $sumName] = $parsed;
        if ($sumName !== null && $sumName !== $name) {
            throw new \RuntimeException("Контрольна сума належить іншому файлу ($sumName).");
        }

        $cmp = version_compare($version, Version::CURRENT);
        $allowSame = $this->field($request, 'allow_same', 2) === '1';
        if ($cmp < 0) {
            throw new \RuntimeException("Версія $version старша за встановлену " . Version::CURRENT . '. Повернення до старої версії — лише з резервної копії.');
        }
        if ($cmp === 0 && !$allowSame) {
            throw new \RuntimeException("Версія $version уже встановлена. Для перевстановлення позначте відповідний пункт.");
        }

        $id = Jobs::newId($this->clock->now());
        $tmp = $this->jobs->spoolDir() . "/.$id.upload";
        $archive->moveTo($tmp);
        $actual = (string) hash_file('sha256', $tmp);
        $head = (string) file_get_contents($tmp, false, null, 0, 4);
        if (!hash_equals($expected, $actual)) {
            @unlink($tmp);
            throw new \RuntimeException('Контрольна сума SHA-256 не збігається: архів пошкоджено або це інший файл.');
        }
        if ($head !== "PK\x03\x04") {
            @unlink($tmp);
            throw new \RuntimeException('Файл не є ZIP-архівом.');
        }
        rename($tmp, $this->jobs->spoolDir() . "/$id.zip");
        $this->jobs->enqueue($id, 'update', $admin, $this->clock->now(), [
            'sha256' => $actual, 'version' => $version, 'file' => $name, 'allow_same' => $allowSame,
        ]);
        $this->audit->event('admin_update_requested', $admin, $this->remote($request), ['job' => $id, 'version' => $version, 'sha256' => $actual]);
        return $id;
    }

    /** @param array<string, mixed> $vars */
    private function updatePage(Response $response, array $vars = [], int $status = 200): Response
    {
        return $this->page($response, 'admin_update', 'update', $vars + [
            'version' => Version::CURRENT,
            'jobs' => array_values(array_filter($this->jobs->list(30), static fn ($j) => $j['type'] === 'update')),
            'hasPassphrase' => $this->cfg->get('BACKUP_PASSPHRASE') !== '',
            'busy' => $this->jobs->busy('update'),
            'maxMb' => intdiv(self::MAX_UPLOAD, 1024 * 1024),
        ], $status);
    }

    // ---- jobs --------------------------------------------------------------------

    public function job(Request $request, Response $response, string $id): Response
    {
        return $this->guarded($request, $response, function () use ($response, $id): Response {
            $job = $this->jobs->get($id);
            if ($job === null) {
                return $response->withStatus(404);
            }
            return $this->page($response, 'admin_job', $job['type'] === 'update' ? 'update' : 'backups', [
                'job' => $job,
                'log' => $this->jobs->log($id),
                'refresh' => in_array($job['state'], ['queued', 'running'], true),
            ]);
        });
    }
}
