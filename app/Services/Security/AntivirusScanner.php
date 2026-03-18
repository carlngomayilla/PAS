<?php

namespace App\Services\Security;

use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AntivirusScanner
{
    public function scanUploadedFile(UploadedFile $file): void
    {
        if (! config('security.uploads.antivirus.enabled', false)) {
            return;
        }

        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new MalwareScanException('Fichier temporaire introuvable pour l analyse antivirus.');
        }

        $binary = (string) config('security.uploads.antivirus.binary', 'clamscan');
        $arguments = config('security.uploads.antivirus.arguments', ['--no-summary']);
        $timeout = (int) config('security.uploads.antivirus.timeout', 30);
        $failOpen = (bool) config('security.uploads.antivirus.fail_open', false);

        $command = array_merge([$binary], is_array($arguments) ? $arguments : [], [$path]);
        $process = new Process($command);
        $process->setTimeout(max(1, $timeout));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            if ($failOpen) {
                report($exception);
                return;
            }

            throw new MalwareScanException('Analyse antivirus interrompue par depassement du temps imparti.', 0, $exception);
        } catch (ProcessFailedException $exception) {
            if ($failOpen) {
                report($exception);
                return;
            }

            throw new MalwareScanException('Echec du lancement du scanner antivirus.', 0, $exception);
        }

        $exitCode = $process->getExitCode();
        $output = trim($process->getOutput().' '.$process->getErrorOutput());

        if ($exitCode === 0) {
            return;
        }

        if ($exitCode === 1) {
            throw new MalwareScanException('Le fichier a ete bloque par le scanner antivirus: '.$output);
        }

        if ($failOpen) {
            report(new MalwareScanException('Scanner antivirus indisponible: '.$output));
            return;
        }

        throw new MalwareScanException('Le scanner antivirus n a pas pu verifier le fichier: '.$output);
    }
}
