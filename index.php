<?php

require __DIR__ . '/pdf_tools.php';

session_start();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function pdf_redirect(): never
{
    header('Location: index.php', true, 303);
    exit;
}

function pdf_flash(string $type, string $message): void
{
    $_SESSION['pdf_flash'] = ['type' => $type, 'message' => $message];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        pdf_flash('error', 'Your session expired. Refresh the page and try again.');
        pdf_redirect();
    }

    try {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload') {
            [$added, $errors] = pdf_upload_documents();
            if ($added !== [] && $errors === []) {
                pdf_flash('success', count($added) . ' PDF' . (count($added) === 1 ? '' : 's') . ' ready to arrange.');
            } elseif ($added !== []) {
                pdf_flash('error', implode(' ', $errors));
            } else {
                pdf_flash('error', implode(' ', $errors) ?: 'No PDFs were added.');
            }
            pdf_redirect();
        }

        if ($action === 'remove') {
            pdf_remove_document((string) ($_POST['document_id'] ?? ''));
            pdf_flash('success', 'PDF removed.');
            pdf_redirect();
        }

        if ($action === 'clear') {
            pdf_clear_documents();
            pdf_flash('success', 'All PDFs cleared.');
            pdf_redirect();
        }

        if ($action === 'merge') {
            $rawOrder = (string) ($_POST['page_order'] ?? '');
            $orderedPages = json_decode($rawOrder, true);
            if (!is_array($orderedPages) || json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('The page order is invalid. Refresh the page and try again.');
            }

            $outputPath = pdf_merge_pages($orderedPages);
            $handle = fopen($outputPath, 'rb');
            if ($handle === false) {
                unlink($outputPath);
                throw new RuntimeException('Unable to read the merged PDF for download.');
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="merged.pdf"');
            header('Content-Length: ' . filesize($outputPath));
            header('X-Content-Type-Options: nosniff');
            fpassthru($handle);
            fclose($handle);
            unlink($outputPath);
            exit;
        }

        throw new RuntimeException('Unknown action.');
    } catch (RuntimeException $exception) {
        pdf_flash('error', $exception->getMessage());
        pdf_redirect();
    }
}

$documents = $_SESSION['pdf_documents'] ?? [];
$flash = $_SESSION['pdf_flash'] ?? null;
unset($_SESSION['pdf_flash']);
$orderedPages = [];
foreach ($documents as $document) {
    for ($page = 1; $page <= $document['pages']; $page++) {
        $orderedPages[] = $document['id'] . ':' . $page;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PDF Magic - Merge PDFs</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<main class="shell">
    <header class="page-header">
        <div class="brand-mark" aria-hidden="true">P</div>
        <div>
            <p class="eyebrow">A simple PDF utility</p>
            <h1>PDF Magic</h1>
            <p class="subtitle">Combine PDFs and put every page in the right order.</p>
        </div>
    </header>

    <?php if (is_array($flash)): ?>
        <div class="notice notice-<?= pdf_escape($flash['type']) ?>" role="status">
            <?= pdf_escape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <section class="panel upload-panel" aria-labelledby="upload-heading">
        <div class="section-heading">
            <div>
                <p class="step-label">Step 1</p>
                <h2 id="upload-heading">Add your PDFs</h2>
            </div>
            <span class="limit-badge">Up to 10 files · 25 MB each</span>
        </div>
        <form method="post" enctype="multipart/form-data" class="upload-form">
            <input type="hidden" name="csrf_token" value="<?= pdf_escape($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="upload">
            <label class="file-picker" for="pdf-files">
                <span class="upload-icon" aria-hidden="true">↑</span>
                <span><strong>Choose PDF files</strong><small>PDF documents only</small></span>
                <input id="pdf-files" type="file" name="pdf_files[]" accept="application/pdf,.pdf" multiple required>
            </label>
            <button class="button button-primary" type="submit">Add PDFs</button>
        </form>

        <?php if ($documents !== []): ?>
            <ul class="document-list" aria-label="Uploaded PDFs">
                <?php foreach ($documents as $document): ?>
                    <li class="document-item">
                        <div class="document-icon" aria-hidden="true">PDF</div>
                        <div class="document-details">
                            <strong><?= pdf_escape($document['name']) ?></strong>
                            <span><?= number_format($document['pages']) ?> <?= $document['pages'] === 1 ? 'page' : 'pages' ?> · <?= number_format($document['size'] / 1024, 0) ?> KB</span>
                        </div>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= pdf_escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="document_id" value="<?= pdf_escape($document['id']) ?>">
                            <button class="button button-quiet" type="submit" aria-label="Remove <?= pdf_escape($document['name']) ?>">Remove</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="panel pages-panel" aria-labelledby="pages-heading">
        <div class="section-heading">
            <div>
                <p class="step-label">Step 2</p>
                <h2 id="pages-heading">Arrange pages</h2>
            </div>
            <?php if ($orderedPages !== []): ?>
                <span class="page-count"><?= number_format(count($orderedPages)) ?> <?= count($orderedPages) === 1 ? 'page' : 'pages' ?></span>
            <?php endif; ?>
        </div>

        <?php if ($orderedPages === []): ?>
            <div class="empty-state">
                <span class="empty-icon" aria-hidden="true">▤</span>
                <p>Your page order will appear here after you add a PDF.</p>
            </div>
        <?php else: ?>
            <p class="help-text">Drag pages to reorder them, or use the arrow buttons. Remove pages you don't need.</p>
            <form method="post" id="merge-form">
                <input type="hidden" name="csrf_token" value="<?= pdf_escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="merge">
                <input type="hidden" id="page-order" name="page_order" value="<?= pdf_escape(json_encode($orderedPages, JSON_THROW_ON_ERROR)) ?>">
                <ol class="page-list" id="page-list" aria-label="Merged PDF page order">
                    <?php foreach ($documents as $document): ?>
                        <?php for ($page = 1; $page <= $document['pages']; $page++): ?>
                            <li class="page-row" draggable="true" data-page="<?= pdf_escape($document['id'] . ':' . $page) ?>">
                                <span class="drag-handle" aria-hidden="true">⠿</span>
                                <span class="page-number" aria-hidden="true"><?= $page ?></span>
                                <span class="page-description"><strong><?= pdf_escape($document['name']) ?></strong><small>Page <?= $page ?></small></span>
                                <span class="page-actions">
                                    <button class="icon-button move-up" type="button" aria-label="Move <?= pdf_escape($document['name']) ?> page <?= $page ?> up" title="Move up">↑</button>
                                    <button class="icon-button move-down" type="button" aria-label="Move <?= pdf_escape($document['name']) ?> page <?= $page ?> down" title="Move down">↓</button>
                                    <button class="icon-button remove-page" type="button" aria-label="Remove <?= pdf_escape($document['name']) ?> page <?= $page ?>" title="Remove page">×</button>
                                </span>
                            </li>
                        <?php endfor; ?>
                    <?php endforeach; ?>
                </ol>
            </form>
            <div class="merge-footer">
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= pdf_escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="clear">
                    <button class="button button-quiet" type="submit">Clear all PDFs</button>
                </form>
                <button class="button button-primary merge-button" type="submit" form="merge-form">Merge &amp; download PDF</button>
            </div>
        <?php endif; ?>
    </section>
    <footer>Files are processed privately on this server and are removed when you clear the list.</footer>
</main>
</body>
</html>
