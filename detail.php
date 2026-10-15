<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function detailEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$productId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$product = false;
$variants = [];
$databaseError = false;

try {
    if ($productId !== false && $productId !== null && $productId > 0) {
        $statement = getDatabaseConnection()->prepare(
            'SELECT p.id, p.name, p.slug, p.description, p.price, p.image,
                    c.name AS category_name, c.slug AS category_slug,
                    COALESCE(SUM(pv.stock), 0) AS stock
             FROM products p
             INNER JOIN categories c ON c.id = p.category_id
             LEFT JOIN product_variants pv ON pv.product_id = p.id
             WHERE p.id = :id AND p.status = :status
             GROUP BY p.id, p.name, p.slug, p.description, p.price, p.image, c.name, c.slug'
        );
        $statement->execute([':id' => $productId, ':status' => 'active']);
        $product = $statement->fetch();
        if ($product === false) {
            $product = [];
        }

        if ($product !== []) {
            $variantStatement = getDatabaseConnection()->prepare(
                'SELECT id, size, color, stock
                 FROM product_variants
                 WHERE product_id = :product_id
                 ORDER BY FIELD(size, "S", "M", "L", "XL", "XXL"), color'
            );
            $variantStatement->execute([':product_id' => $productId]);
            $variants = $variantStatement->fetchAll();
        }
    }
} catch (PDOException $exception) {
    error_log('BrayStyle product detail error: ' . $exception->getMessage());
    $databaseError = true;
}

$notFound = $productId === false || $productId === null || $productId < 1 || ($product !== false && $product === []);
if ($notFound) {
    http_response_code(404);
}

$pageTitle = $product && $product !== [] ? $product['name'] . ' — BrayStyle' : 'Detail produk — BrayStyle';
$metaDescription = $product && $product !== [] ? $product['description'] : 'Detail koleksi cutbray BrayStyle.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main class="page-shell detail-page">
    <nav class="breadcrumbs" aria-label="Breadcrumb">
        <a href="index.php">Beranda</a><span aria-hidden="true">/</span><a href="produk.php">Koleksi</a>
        <?php if ($product && $product !== []): ?><span aria-hidden="true">/</span><span><?= detailEscape($product['name']) ?></span><?php endif; ?>
    </nav>

    <?php if ($databaseError): ?>
        <div class="catalog-message detail-notice" role="status"><span class="message-icon" aria-hidden="true">!</span><div><strong>Detail produk belum dapat dimuat.</strong><p>Pastikan koneksi database tersedia, lalu coba lagi.</p></div></div>
    <?php elseif ($notFound || !$product): ?>
        <div class="empty-state detail-empty">
            <span class="empty-state-icon" aria-hidden="true">?</span>
            <h1>Produk tidak ditemukan.</h1>
            <p>Produk ini mungkin sudah tidak tersedia di koleksi kami.</p>
            <a class="button button-dark" href="produk.php">Kembali ke koleksi</a>
        </div>
    <?php else: ?>
        <?php
        $selectedVariantId = null;
        $selectedVariantStock = 0;
        foreach ($variants as $variant) {
            if ((int) $variant['stock'] > 0) {
                $selectedVariantId = (int) $variant['id'];
                $selectedVariantStock = (int) $variant['stock'];
                break;
            }
        }
        $imageName = isset($product['image']) ? basename((string) $product['image']) : '';
        $hasImage = $imageName !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $imageName);
        $tone = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $product['category_slug']));
        ?>
        <section class="detail-layout">
            <div class="detail-visual product-visual product-tone-<?= detailEscape($tone) ?>">
                <?php if ($hasImage): ?>
                    <img src="uploads/<?= rawurlencode($imageName) ?>" alt="<?= detailEscape($product['name']) ?>">
                <?php else: ?>
                    <span class="product-visual-label">BRAYSTYLE <span>· CUTBRAY EDIT</span></span>
                    <svg class="pants-illustration" viewBox="0 0 180 230" aria-hidden="true"><path d="M45 24h90l-6 72 30 119h-53l-16-91-16 91H21L51 96z"></path><path class="pants-detail" d="M48 34h84M87 27v97m-33-22h21m48 0h-21"></path></svg>
                    <span class="visual-caption"><?= detailEscape($product['category_name']) ?> / BRAYSTYLE</span>
                <?php endif; ?>
                <span class="product-badge"><?= (int) $product['stock'] > 0 ? 'READY TO WEAR' : 'SEGERA HADIR' ?></span>
            </div>

            <div class="detail-info">
                <span class="eyebrow"><?= detailEscape($product['category_name']) ?> · BRAYSTYLE</span>
                <h1><?= detailEscape($product['name']) ?></h1>
                <p class="detail-price">Rp <?= number_format((float) $product['price'], 0, ',', '.') ?></p>
                <p class="detail-description"><?= detailEscape($product['description']) ?></p>
                <div class="detail-stock"><span class="stock-dot<?= (int) $product['stock'] < 1 ? ' is-empty' : '' ?>"></span><?= (int) $product['stock'] ?> item tersedia</div>

                <?php if ($variants): ?>
                    <form class="detail-order-form" action="cart.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= detailEscape(shopCsrfToken()) ?>">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="return_product_id" value="<?= (int) $product['id'] ?>">
                        <label for="variant-id">Ukuran &amp; warna</label>
                        <select id="variant-id" name="variant_id" required <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>
                            <?php foreach ($variants as $variant): ?>
                                <option value="<?= (int) $variant['id'] ?>" data-stock="<?= (int) $variant['stock'] ?>" <?= (int) $variant['stock'] < 1 ? 'disabled' : '' ?> <?= (int) $variant['id'] === $selectedVariantId ? 'selected' : '' ?>>
                                    <?= detailEscape($variant['size']) ?> · <?= detailEscape($variant['color']) ?><?= (int) $variant['stock'] < 1 ? ' — stok habis' : ' — stok ' . (int) $variant['stock'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="detail-quantity">
                            <label for="quantity">Jumlah</label>
                            <input id="quantity" type="number" name="quantity" min="1" max="<?= max(1, $selectedVariantStock) ?>" value="1" required <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>
                        </div>
                        <button class="button button-dark detail-add-button" type="submit" <?= (int) $product['stock'] < 1 ? 'disabled' : '' ?>>Tambah ke keranjang <span aria-hidden="true">↗</span></button>
                    </form>
                <?php else: ?>
                    <p class="variant-note">Pilihan ukuran dan warna produk ini belum tersedia.</p>
                <?php endif; ?>

                <a class="text-link detail-back-link" href="produk.php">← Kembali ke koleksi</a>
            </div>
        </section>
        <script>
            (function () {
                var variant = document.getElementById('variant-id');
                var quantity = document.getElementById('quantity');
                if (variant && quantity) {
                    variant.addEventListener('change', function () {
                        var stock = Number(variant.options[variant.selectedIndex].getAttribute('data-stock')) || 0;
                        quantity.max = String(Math.max(1, stock));
                        if (Number(quantity.value) > stock) {
                            quantity.value = String(Math.max(1, stock));
                        }
                    });
                }
            }());
        </script>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
