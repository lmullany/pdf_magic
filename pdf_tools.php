<?php

function pdf_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function pdf_work_dir(): string
{
    return sys_get_temp_dir() . '/pdf_magic_' . hash('sha256', session_id());
}

function pdf_ensure_work_dir(): string
{
    $directory = pdf_work_dir();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create a private temporary directory for your PDFs.');
    }

    return $directory;
}

function pdf_document_path(string $id): string
{
    if (!preg_match('/^[a-f0-9]{24}$/', $id)) {
        throw new RuntimeException('The selected PDF is invalid.');
    }

    return pdf_work_dir() . '/' . $id . '.pdf';
}

function pdf_run_qpdf(array $arguments): array
{
    $binary = getenv('QPDF_BINARY') ?: '/opt/bin/qpdf';
    $command = array_merge([$binary], $arguments);
    $pipes = [];
    $process = @proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start qpdf. Confirm qpdf is on PATH and PHP proc_open is enabled.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, trim($stdout), trim($stderr)];
}

function pdf_run_qpdf_checked(array $arguments, string $failureMessage): string
{
    [$exitCode, $stdout, $stderr] = pdf_run_qpdf($arguments);
    if ($exitCode !== 0 && $exitCode !== 3) {
        $details = $stderr !== '' ? $stderr : $stdout;
        throw new RuntimeException($details !== '' ? $failureMessage . ' ' . $details : $failureMessage);
    }

    return $stdout;
}

function pdf_upload_documents(): array
{
    if (!isset($_FILES['pdf_files']) || !is_array($_FILES['pdf_files']['name'] ?? null)) {
        throw new RuntimeException('Choose one or more PDF files to upload.');
    }

    $files = $_FILES['pdf_files'];
    $count = count($files['name']);
    if ($count === 0) {
        throw new RuntimeException('Choose one or more PDF files to upload.');
    }

    $currentDocuments = $_SESSION['pdf_documents'] ?? [];
    if (count($currentDocuments) + $count > 10) {
        throw new RuntimeException('You can work with up to 10 PDFs at a time.');
    }

    $directory = pdf_ensure_work_dir();
    $added = [];
    $errors = [];

    for ($i = 0; $i < $count; $i++) {
        $name = basename((string) ($files['name'][$i] ?? 'document.pdf'));
        $error = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $temporaryPath = (string) ($files['tmp_name'][$i] ?? '');
        $size = (int) ($files['size'][$i] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = $name . ': ' . match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'the file exceeds the server upload size limit.',
                UPLOAD_ERR_PARTIAL => 'the upload was incomplete.',
                UPLOAD_ERR_NO_FILE => 'no file was selected.',
                default => 'the upload failed.',
            };
            continue;
        }

        if ($size <= 0 || $size > 25 * 1024 * 1024) {
            $errors[] = $name . ': files must be between 1 byte and 25 MB.';
            continue;
        }

        $id = bin2hex(random_bytes(12));
        $storedPath = $directory . '/' . $id . '.pdf';
        if (!is_uploaded_file($temporaryPath) || !move_uploaded_file($temporaryPath, $storedPath)) {
            $errors[] = $name . ': the uploaded file could not be saved.';
            continue;
        }

        try {
            pdf_run_qpdf_checked(['--check', $storedPath], $name . ' is not a valid, readable PDF.');
            $pageCountText = pdf_run_qpdf_checked(
                ['--show-npages', $storedPath],
                'Unable to read the page count for ' . $name . '.'
            );
            if (!preg_match('/^\d+$/', $pageCountText) || (int) $pageCountText < 1) {
                throw new RuntimeException($name . ' does not contain any pages.');
            }

            $added[] = [
                'id' => $id,
                'name' => $name,
                'size' => $size,
                'pages' => (int) $pageCountText,
            ];
        } catch (RuntimeException $exception) {
            unlink($storedPath);
            $errors[] = $exception->getMessage();
        }
    }

    if ($added !== []) {
        $_SESSION['pdf_documents'] = array_merge($currentDocuments, $added);
    }

    return [$added, $errors];
}

function pdf_merge_pages(array $orderedPages): string
{
    $documents = $_SESSION['pdf_documents'] ?? [];
    if ($orderedPages === []) {
        throw new RuntimeException('Add at least one page to the merge order.');
    }

    $command = ['--empty', '--pages'];
    foreach ($orderedPages as $item) {
        if (!is_string($item) || !preg_match('/^([a-f0-9]{24}):([1-9]\d*)$/', $item, $matches)) {
            throw new RuntimeException('The page order contains an invalid entry.');
        }

        $documentId = $matches[1];
        $pageNumber = (int) $matches[2];
        $document = null;
        foreach ($documents as $candidate) {
            if ($candidate['id'] === $documentId) {
                $document = $candidate;
                break;
            }
        }

        if ($document === null || $pageNumber > $document['pages']) {
            throw new RuntimeException('The page order refers to a PDF page that is no longer available.');
        }

        $path = pdf_document_path($documentId);
        if (!is_file($path)) {
            throw new RuntimeException('An uploaded PDF is no longer available. Upload it again to continue.');
        }

        $command[] = $path;
        $command[] = (string) $pageNumber;
    }

    $outputPath = pdf_work_dir() . '/merged-' . bin2hex(random_bytes(8)) . '.pdf';
    $command[] = '--';
    $command[] = $outputPath;

    try {
        pdf_run_qpdf_checked($command, 'qpdf could not create the merged PDF.');
        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('qpdf did not produce a merged PDF.');
        }
    } catch (RuntimeException $exception) {
        if (is_file($outputPath)) {
            unlink($outputPath);
        }
        throw $exception;
    }

    return $outputPath;
}

function pdf_remove_document(string $id): void
{
    $documents = $_SESSION['pdf_documents'] ?? [];
    $found = false;
    foreach ($documents as $document) {
        if ($document['id'] === $id) {
            $found = true;
            break;
        }
    }

    if (!$found) {
        throw new RuntimeException('That PDF is no longer in this merge.');
    }

    $path = pdf_document_path($id);
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Unable to remove the selected PDF.');
    }

    $_SESSION['pdf_documents'] = array_values(array_filter(
        $documents,
        static fn (array $document): bool => $document['id'] !== $id
    ));
}

function pdf_clear_documents(): void
{
    foreach ($_SESSION['pdf_documents'] ?? [] as $document) {
        $path = pdf_document_path($document['id']);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('Unable to remove all uploaded PDFs. Try clearing them again.');
        }
    }

    $_SESSION['pdf_documents'] = [];
}
