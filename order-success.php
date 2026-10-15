<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function successEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$orderNumber = isset($_SESSION['last_order_number']) && is_string($_SESSION['last_order_number'])
    ? $_SESSION['last_order_number']
    : '';
$order = false;
$items = [];
$databaseError = false;

if ($orderNumber === '') {
    header('Location: produk.php', true, 303);
    exit;
}

try {
    $order = dbQueryOne(
        'SELECT order_number, customer_name, phone, address, notes, payment_method, total, status, created_at
         FROM orders
         WHERE order_number = :order_number',
        [':order_number' => $orderNumber]
    );
    if ($order === false) {
        $databaseError = true;
    } else {
        $items = dbQuery(
            'SELECT product_name, size, color, price, quantity, subtotal
             FROM order_items
             INNER JOIN orders ON orders.id = order_items.order_id
             WHERE orders.order_number = :order_number
             ORDER BY order_items.id',
            [':order_number' => $orderNumber]
        );
        if ($items === false) {
            $databaseError = true;
        }
    }
} catch (PDOException $exception) {
    error_log('BrayStyle order confirmation error: ' . $exception->getMessage());
    $databaseError = true;
}

if (!$databaseError && $order) {
    unset($_SESSION['last_order_number']);
}

$pageTitle = 'Pesanan berhasil — BrayStyle';
$metaDescription = 'Ringkasan pesanan BrayStyle berhasil dibuat.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main class="page-shell success-page">
    <?php if ($databaseError || !$order): ?>
        <section class="success-card">
            <span class="success-mark" aria-hidden="true">!</span>
            <span class="eyebrow">KONFIRMASI PESANAN</span>
            <h1>Pesanan tersimpan.</h1>
            <p>Pesananmu berhasil dibuat, tetapi ringkasannya belum dapat dimuat. Hubungi toko dan sertakan nomor pesanan berikut:</p>
            <strong class="success-order-number"><?= successEscape($orderNumber) ?></strong>
            <a class="button button-dark" href="produk.php">Kembali ke katalog</a>
        </section>
    <?php else: ?>
        <section class="success-card">
            <span class="success-mark" aria-hidden="true">✓</span>
            <span class="eyebrow">TERIMA KASIH SUDAH MEMILIH BRAYSTYLE</span>
            <h1>Pesananmu berhasil dibuat.</h1>
            <p>Pesanan sedang menunggu konfirmasi. Simpan nomor pesanan ini untuk referensi.</p>
            <span class="success-label">NOMOR PESANAN</span>
            <strong class="success-order-number"><?= successEscape($order['order_number']) ?></strong>

            <div class="success-details">
                <div><span>Nama</span><strong><?= successEscape($order['customer_name']) ?></strong></div>
                <div><span>WhatsApp</span><strong><?= successEscape($order['phone']) ?></strong></div>
                <div><span>Alamat</span><strong><?= nl2br(successEscape($order['address'])) ?></strong></div>
                <div><span>Pembayaran</span><strong><?= $order['payment_method'] === 'cod' ? 'COD' : 'Transfer bank' ?></strong></div>
                <div><span>Status</span><strong>Baru</strong></div>
                <?php if (!empty($order['notes'])): ?><div><span>Catatan</span><strong><?= nl2br(successEscape($order['notes'])) ?></strong></div><?php endif; ?>
            </div>

            <div class="success-items">
                <h2>Ringkasan produk</h2>
                <?php foreach ($items as $item): ?>
                    <div class="success-item">
                        <span><?= (int) $item['quantity'] ?>× <?= successEscape($item['product_name']) ?><small><?= successEscape($item['size']) ?> · <?= successEscape($item['color']) ?></small></span>
                        <strong>Rp <?= number_format((float) $item['subtotal'], 0, ',', '.') ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="summary-total"><span>Total pesanan</span><strong>Rp <?= number_format((float) $order['total'], 0, ',', '.') ?></strong></div>
            </div>
            <a class="button button-dark" href="produk.php">Kembali ke katalog <span aria-hidden="true">↗</span></a>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
