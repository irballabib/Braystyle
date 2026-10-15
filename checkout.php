<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function checkoutEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function checkoutPostValue($name)
{
    return isset($_POST[$name]) && is_string($_POST[$name]) ? trim($_POST[$name]) : '';
}

$cart = [];
foreach ($_SESSION['cart'] as $variantId => $quantity) {
    $variantId = filter_var($variantId, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
    if ($variantId !== false && $variantId !== null && $variantId > 0 && $quantity !== false && $quantity !== null && $quantity > 0) {
        $cart[(int) $variantId] = (int) $quantity;
    }
}
$_SESSION['cart'] = $cart;

if (!$cart) {
    header('Location: cart.php', true, 303);
    exit;
}

$items = [];
$databaseError = false;
$formError = '';
$oldInput = [
    'customer_name' => '',
    'phone' => '',
    'address' => '',
    'notes' => '',
    'payment_method' => 'cod',
];

try {
    $placeholders = implode(',', array_fill(0, count($cart), '?'));
    $statement = getDatabaseConnection()->prepare(
        'SELECT pv.id AS variant_id, pv.size, pv.color, pv.stock,
                p.id AS product_id, p.name, p.price, p.image, p.status,
                c.name AS category_name
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         INNER JOIN categories c ON c.id = p.category_id
         WHERE pv.id IN (' . $placeholders . ')'
    );
    $statement->execute(array_keys($cart));
    foreach ($statement->fetchAll() as $item) {
        $item['quantity'] = $cart[(int) $item['variant_id']];
        $item['subtotal'] = (float) $item['price'] * $item['quantity'];
        $items[] = $item;
    }
} catch (PDOException $exception) {
    error_log('BrayStyle checkout cart error: ' . $exception->getMessage());
    $databaseError = true;
}

if (!$databaseError && count($items) !== count($cart)) {
    $databaseError = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$databaseError) {
    $oldInput['customer_name'] = checkoutPostValue('customer_name');
    $oldInput['phone'] = checkoutPostValue('phone');
    $oldInput['address'] = checkoutPostValue('address');
    $oldInput['notes'] = checkoutPostValue('notes');
    $oldInput['payment_method'] = checkoutPostValue('payment_method');
    $token = checkoutPostValue('csrf_token');

    if (!shopIsCsrfValid($token)) {
        $formError = 'Formulir kedaluwarsa. Muat ulang halaman dan coba lagi.';
    } elseif (
        $oldInput['customer_name'] === ''
        || strlen($oldInput['customer_name']) > 100
        || !preg_match('/^\+?[0-9().\s-]{8,20}$/', $oldInput['phone'])
        || $oldInput['address'] === ''
        || strlen($oldInput['address']) > 2000
        || strlen($oldInput['notes']) > 1000
        || !in_array($oldInput['payment_method'], ['cod', 'transfer'], true)
    ) {
        $formError = 'Periksa kembali nama, nomor WhatsApp, alamat, catatan, dan metode pembayaran.';
    } else {
        $pdo = null;
        try {
            $pdo = getDatabaseConnection();
            $pdo->beginTransaction();
            $lockedItems = [];
            $total = 0;

            foreach ($cart as $variantId => $quantity) {
                $lock = $pdo->prepare(
                    'SELECT pv.id, pv.size, pv.color, pv.stock,
                            p.id AS product_id, p.name, p.price, p.status
                     FROM product_variants pv
                     INNER JOIN products p ON p.id = pv.product_id
                     WHERE pv.id = :variant_id
                     FOR UPDATE'
                );
                $lock->execute([':variant_id' => $variantId]);
                $current = $lock->fetch();

                if (!$current || $current['status'] !== 'active' || (int) $current['stock'] < $quantity) {
                    throw new RuntimeException('Stok produk berubah. Perbarui keranjang sebelum membuat pesanan.');
                }

                $current['quantity'] = $quantity;
                $current['subtotal'] = (float) $current['price'] * $quantity;
                $lockedItems[] = $current;
                $total += $current['subtotal'];
            }

            $orderNumber = 'TC-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $insertOrder = $pdo->prepare(
                'INSERT INTO orders (user_id, order_number, customer_name, phone, address, notes, payment_method, total, status)
                 VALUES (NULL, :order_number, :customer_name, :phone, :address, :notes, :payment_method, :total, :status)'
            );
            $insertOrder->execute([
                ':order_number' => $orderNumber,
                ':customer_name' => $oldInput['customer_name'],
                ':phone' => $oldInput['phone'],
                ':address' => $oldInput['address'],
                ':notes' => $oldInput['notes'] !== '' ? $oldInput['notes'] : null,
                ':payment_method' => $oldInput['payment_method'],
                ':total' => $total,
                ':status' => 'pending',
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id, product_variant_id, product_name, size, color, price, quantity, subtotal)
                 VALUES (:order_id, :variant_id, :product_name, :size, :color, :price, :quantity, :subtotal)'
            );
            $updateStock = $pdo->prepare(
                'UPDATE product_variants
                 SET stock = stock - :quantity
                 WHERE id = :variant_id AND stock >= :available_quantity'
            );

            foreach ($lockedItems as $item) {
                $updateStock->execute([
                    ':quantity' => $item['quantity'],
                    ':variant_id' => $item['id'],
                    ':available_quantity' => $item['quantity'],
                ]);
                if ($updateStock->rowCount() !== 1) {
                    throw new RuntimeException('Stok produk berubah. Perbarui keranjang sebelum membuat pesanan.');
                }

                $insertItem->execute([
                    ':order_id' => $orderId,
                    ':variant_id' => $item['id'],
                    ':product_name' => $item['name'],
                    ':size' => $item['size'],
                    ':color' => $item['color'],
                    ':price' => $item['price'],
                    ':quantity' => $item['quantity'],
                    ':subtotal' => $item['subtotal'],
                ]);
            }

            $pdo->commit();
            $_SESSION['cart'] = [];
            $_SESSION['last_order_number'] = $orderNumber;
            header('Location: order-success.php', true, 303);
            exit;
        } catch (RuntimeException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $formError = $exception->getMessage();
        } catch (PDOException $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('BrayStyle order creation error: ' . $exception->getMessage());
            $formError = 'Pesanan belum berhasil disimpan. Silakan coba lagi.';
        }
    }
}

$total = 0;
$hasUnavailableItem = false;
foreach ($items as $item) {
    $total += $item['subtotal'];
    if ($item['status'] !== 'active' || (int) $item['stock'] < (int) $item['quantity']) {
        $hasUnavailableItem = true;
    }
}

$pageTitle = 'Checkout — BrayStyle';
$metaDescription = 'Isi data pengiriman untuk menyelesaikan pesanan BrayStyle.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main class="page-shell checkout-page">
    <div class="cart-heading">
        <div><span class="eyebrow">TINGGAL SELANGKAH LAGI</span><h1>Selesaikan <span>pesanan</span></h1></div>
        <a class="text-link" href="cart.php">← Kembali ke keranjang</a>
    </div>

    <?php if ($databaseError): ?>
        <div class="catalog-message" role="status"><span class="message-icon" aria-hidden="true">!</span><div><strong>Ringkasan pesanan belum dapat dimuat.</strong><p>Periksa koneksi database dan keranjangmu, lalu coba lagi.</p><a class="text-link" href="cart.php">Kembali ke keranjang</a></div></div>
    <?php else: ?>
        <?php if ($formError !== ''): ?><div class="checkout-error" role="alert"><?= checkoutEscape($formError) ?></div><?php endif; ?>
        <?php if ($hasUnavailableItem): ?><div class="checkout-error" role="alert">Stok berubah. Perbarui jumlah atau hapus produk yang tidak tersedia di <a href="cart.php">keranjang</a>.</div><?php endif; ?>

        <div class="checkout-layout">
            <form class="checkout-form" action="checkout.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= checkoutEscape(shopCsrfToken()) ?>">
                <fieldset>
                    <legend>Informasi pembeli</legend>
                    <label for="customer-name">Nama pembeli</label>
                    <input id="customer-name" name="customer_name" type="text" maxlength="100" value="<?= checkoutEscape($oldInput['customer_name']) ?>" autocomplete="name" required>

                    <label for="customer-phone">Nomor WhatsApp</label>
                    <input id="customer-phone" name="phone" type="tel" maxlength="20" placeholder="Contoh: 081234567890" value="<?= checkoutEscape($oldInput['phone']) ?>" autocomplete="tel" required>

                    <label for="customer-address">Alamat lengkap</label>
                    <textarea id="customer-address" name="address" rows="4" maxlength="2000" autocomplete="street-address" required><?= checkoutEscape($oldInput['address']) ?></textarea>

                    <label for="customer-notes">Catatan <span>(opsional)</span></label>
                    <textarea id="customer-notes" name="notes" rows="3" maxlength="1000" placeholder="Patokan alamat atau pesan untuk toko"><?= checkoutEscape($oldInput['notes']) ?></textarea>
                </fieldset>

                <fieldset class="payment-fieldset">
                    <legend>Metode pembayaran</legend>
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="cod" <?= $oldInput['payment_method'] === 'cod' ? 'checked' : '' ?>>
                        <span><strong>COD</strong><small>Bayar saat pesanan diterima</small></span>
                    </label>
                    <label class="payment-option">
                        <input type="radio" name="payment_method" value="transfer" <?= $oldInput['payment_method'] === 'transfer' ? 'checked' : '' ?>>
                        <span><strong>Transfer bank</strong><small>Informasi pembayaran diberikan setelah pesanan dibuat</small></span>
                    </label>
                </fieldset>

                <button class="button button-dark checkout-submit" type="submit" <?= $hasUnavailableItem ? 'disabled' : '' ?>>Buat pesanan <span aria-hidden="true">↗</span></button>
            </form>

            <aside class="checkout-summary">
                <h2>Ringkasan pesanan</h2>
                <?php foreach ($items as $item): ?>
                    <div class="checkout-item">
                        <span class="checkout-item-quantity"><?= (int) $item['quantity'] ?>×</span>
                        <span class="checkout-item-name"><?= checkoutEscape($item['name']) ?><small><?= checkoutEscape($item['size']) ?> · <?= checkoutEscape($item['color']) ?></small></span>
                        <strong>Rp <?= number_format((float) $item['subtotal'], 0, ',', '.') ?></strong>
                    </div>
                <?php endforeach; ?>
                <div class="summary-total"><span>Total belanja</span><strong>Rp <?= number_format($total, 0, ',', '.') ?></strong></div>
                <p>Ongkos kirim akan dikonfirmasi oleh toko setelah pesanan diterima.</p>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
