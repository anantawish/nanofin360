<?php
declare(strict_types=1);
$flashes = consume_flashes();
foreach ($flashes as $flash):
    $type = (string)($flash['type'] ?? 'info');
    $message = (string)($flash['message'] ?? '');
?>
    <div class="alert alert-<?php echo h($type); ?> alert-dismissible fade show" role="alert">
        <?php echo h($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endforeach; ?>
