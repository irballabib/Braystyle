<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function cartEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function cartRedirect()
{
    header('Location: cart.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!shopIsCsrfValid($token)) {
        http_response_code(400);
        shopFlash('Permintaan tidak dapat diverifikasi. Silakan coba lagi.');
        cartRedirect();
    }

    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    $variantId = isset($_POST['variant_id']) ? filter_var($_POST['variant_id'], FILTER_VALIDATE_INT) : false;

    if ($action === 'remove' && $variantId !== false && $variantId !== null && $variantId > 0) {
        unset($_SESSION['cart'][$variantId]);
        shopFlash('Produk dihapus dari keranjang.');
        cartRedirect();
    }

    if ($action !== 'add' && $action !== 'update') {
        shopFlash('Aksi keranjang tidak dikenali.');
        cartRedirect();
    }

    $quantity = isset($_POST['quantity']) ? filter_var($_POST['quantity'], FILTER_VALIDATE_INT) : false;
    if ($variantId === false || $variantId === null || $variantId < 1 || $quantity === false || $quantity === null || $quantity < 1) {
        shopFlash('Ukuran, warna, atau jumlah produk tidak valid.');
        cartRedirect();
    }

    try {
        $statement = getDatabaseConnection()->prepare(
            'SELECT pv.id, pv.stock, p.status
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             WHERE pv.id = :variant_id'
        );
        $statement->execute([':variant_id' => $variantId]);
        $variant = $statement->fetch();

        if (!$variant || $variant['status'] !== 'active' || (int) $variant['stock'] < 1) {
            shopFlash('Pilihan produk ini sedang tidak tersedia.');
            cartRedirect();
        }

        $targetQuantity = $action === 'add'
            ? (isset($_SESSION['cart'][$variantId]) ? (int) $_SESSION['cart'][$variantId] : 0) + $quantity
            : $quantity;

        if ($targetQuantity > (int) $variant['stock']) {
            shopFlash('Jumlah melebihi stok yang tersedia (' . (int) $variant['stock'] . ').');
            cartRedirect();
        }

        $_SESSION['cart'][$variantId] = $targetQuantity;
        shopFlash($action === 'add' ? 'Produk ditambahkan ke keranjang.' : 'Jumlah produk berhasil diperbarui.');
    } catch (PDOException $exception) {
        error_log('BrayStyle cart update error: ' . $exception->getMessage());
        shopFlash('Keranjang tidak dapat diperbarui karena koneksi database bermasalah.');
    }

    cartRedirect();
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

$items = [];
$databaseError = false;
$staleItemCount = 0;
if ($cart) {
    try {
        $placeholders = implode(',', array_fill(0, count($cart), '?'));
        $statement = getDatabaseConnection()->prepare(
            'SELECT pv.id AS variant_id, pv.size, pv.color, pv.stock,
                    p.id AS product_id, p.name, p.slug, p.price, p.image, p.status,
                    c.name AS category_name
             FROM product_variants pv
             INNER JOIN products p ON p.id = pv.product_id
             INNER JOIN categories c ON c.id = p.category_id
             WHERE pv.id IN (' . $placeholders . ')'
        );
        $statement->execute(array_keys($cart));
        $rows = $statement->fetchAll();
        $foundVariants = [];
        foreach ($rows as $row) {
            $variantId = (int) $row['variant_id'];
            $row['quantity'] = $cart[$variantId];
            $row['subtotal'] = (float) $row['price'] * $row['quantity'];
            $items[] = $row;
            $foundVariants[$variantId] = true;
        }
        foreach ($cart as $variantId => $quantity) {
            if (!isset($foundVariants[$variantId])) {
                unset($_SESSION['cart'][$variantId]);
                $staleItemCount++;
            }
        }
        $cart = $_SESSION['cart'];
    } catch (PDOException $exception) {
        error_log('BrayStyle cart read error: ' . $exception->getMessage());
        $databaseError = true;
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
$notice = shopFlash();
if ($staleItemCount > 0) {
    $notice = $staleItemCount . ' produk yang sudah tidak tersedia dihapus dari keranjang.';
}

$pageTitle = 'Keranjang Belanja — BrayStyle';
$metaDescription = 'Tinjau pilihan celana dan jumlah belanja BrayStyle.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main class="page-shell cart-page">
    <div class="cart-heading">
        <div>
            <span class="eyebrow">PILIHANMU, SIAP DIBAWA PULANG</span>
            <h1>Keranjang <span>belanja</span></h1>
        </div>
        <a class="text-link" href="produk.php">← Lanjut belanja</a>
    </div>

    <?php if ($notice): ?>
        <div class="cart-notice" role="status"><?= cartEscape($notice) ?></div>
    <?php endif; ?>

    <?php if ($databaseError): ?>
        <div class="catalog-message" role="status"><span class="message-icon" aria-hidden="true">!</span><div><strong>Keranjang belum dapat dimuat.</strong><p>Pastikan koneksi database tersedia, lalu coba muat ulang halaman.</p></div></div>
    <?php elseif (!$items): ?>
        <div class="empty-state cart-empty">
            <span class="empty-state-icon" aria-hidden="true">▱</span>
            <h2>Keranjangmu masih kosong.</h2>
            <p>Yuk, temukan celana yang nyaman untuk menemani harimu.</p>
            <a class="button button-dark" href="produk.php">Lihat produk <span aria-hidden="true">↗</span></a>
        </div>
    <?php else: ?>
        <div class="cart-layout">
            <section class="cart-items" aria-label="Daftar produk dalam keranjang">
                <?php foreach ($items as $item): ?>
                    <?php
                    $imageName = isset($item['image']) ? basename((string) $item['image']) : '';
                    $hasImage = $imageName !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $imageName);
                    $available = $item['status'] === 'active' && (int) $item['stock'] >= (int) $item['quantity'];
                    ?>
                    <article class="cart-item">
                        <a class="cart-item-image" href="detail.php?id=<?= (int) $item['product_id'] ?>">
                            <?php if ($hasImage): ?>
                                <img src="uploads/<?= rawurlencode($imageName) ?>" alt="<?= cartEscape($item['name']) ?>">
                            <?php else: ?>
                                <svg class="pants-illustration" viewBox="0 0 180 230" aria-hidden="true"><path d="M45 24h90l-6 72 30 119h-53l-16-91-16 91H21L51 96z"></path><path class="pants-detail" d="M48 34h84M87 27v97"></path></svg>
                            <?php endif; ?>
                        </a>
                        <div class="cart-item-info">
                            <span class="product-category"><?= cartEscape($item['category_name']) ?></span>
                            <h2><a href="detail.php?id=<?= (int) $item['product_id'] ?>"><?= cartEscape($item['name']) ?></a></h2>
                            <p>Ukuran <?= cartEscape($item['size']) ?> · <?= cartEscape($item['color']) ?></p>
                            <span class="cart-unit-price">Rp <?= number_format((float) $item['price'], 0, ',', '.') ?> / item</span>
                            <div class="cart-item-controls">
                                <form class="cart-quantity-form" action="cart.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= cartEscape(shopCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                                    <label class="sr-only" for="quantity-<?= (int) $item['variant_id'] ?>">Jumlah <?= cartEscape($item['name']) ?></label>
                                    <input id="quantity-<?= (int) $item['variant_id'] ?>" type="number" name="quantity" min="1" max="<?= max(1, (int) $item['stock']) ?>" value="<?= (int) $item['quantity'] ?>">
                                    <button type="submit">Perbarui</button>
                                </form>
                                <form action="cart.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= cartEscape(shopCsrfToken()) ?>">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="variant_id" value="<?= (int) $item['variant_id'] ?>">
                                    <button class="cart-remove" type="submit">Hapus</button>
                                </form>
                            </div>
                            <?php if (!$available): ?>
                                <span class="cart-unavailable"><?= $item['status'] !== 'active' ? 'Produk sudah tidak aktif.' : 'Stok tersisa ' . (int) $item['stock'] . ' item. Perbarui jumlah sebelum checkout.' ?></span>
                            <?php endif; ?>
                        </div>
                        <strong class="cart-subtotal">Rp <?= number_format((float) $item['subtotal'], 0, ',', '.') ?></strong>
                    </article>
                <?php endforeach; ?>
            </section>

            <aside class="cart-summary">
                <h2>Ringkasan belanja</h2>
                <div class="summary-row"><span>Jumlah item</span><span><?= (int) shopCartItemCount() ?> item</span></div>
                <div class="summary-row"><span>Subtotal</span><strong>Rp <?= number_format($total, 0, ',', '.') ?></strong></div>
                <div class="summary-total"><span>Total</span><strong>Rp <?= number_format($total, 0, ',', '.') ?></strong></div>
                <?php if ($hasUnavailableItem): ?>
                    <button class="button button-dark checkout-disabled" type="button" disabled>Perbarui stok untuk checkout</button>
                    <p>Sesuaikan atau hapus produk yang stoknya tidak mencukupi.</p>
                <?php else: ?>
                    <a class="button button-dark checkout-disabled" href="checkout.php">Lanjut ke checkout <span aria-hidden="true">↗</span></a>
                    <p>Pastikan ukuran dan warna pilihanmu sudah benar.</p>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
