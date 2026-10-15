<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function catalogEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$searchTerm = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 100)) : '';
$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$categoryId = $categoryId !== false && $categoryId !== null && $categoryId > 0 ? $categoryId : null;
$categories = [];
$products = [];
$databaseError = false;

try {
    $pdo = getDatabaseConnection();
    $categories = $pdo->query(
        'SELECT c.id, c.name, COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.status = "active"
         GROUP BY c.id, c.name
         ORDER BY c.name'
    )->fetchAll();

    $sql = 'SELECT p.id, p.name, p.slug, p.description, p.price, p.image,
                   c.name AS category_name, c.slug AS category_slug,
                   COALESCE(SUM(pv.stock), 0) AS stock,
                   MIN(CASE WHEN pv.stock > 0 THEN pv.id END) AS default_variant_id
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            LEFT JOIN product_variants pv ON pv.product_id = p.id
            WHERE p.status = :status';
    $parameters = [':status' => 'active'];

    if ($categoryId !== null) {
        $sql .= ' AND c.id = :category_id';
        $parameters[':category_id'] = $categoryId;
    }
    if ($searchTerm !== '') {
        $sql .= ' AND (p.name LIKE :product_name OR p.description LIKE :product_description OR c.name LIKE :category_name)';
        $pattern = '%' . $searchTerm . '%';
        $parameters[':product_name'] = $pattern;
        $parameters[':product_description'] = $pattern;
        $parameters[':category_name'] = $pattern;
    }

    $sql .= ' GROUP BY p.id, p.name, p.slug, p.description, p.price, p.image, c.name, c.slug
              ORDER BY p.created_at DESC, p.id DESC';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $products = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('BrayStyle product list error: ' . $exception->getMessage());
    $databaseError = true;
}

$pageTitle = 'Produk — BrayStyle';
$metaDescription = 'Cari celana cutbray modern dengan beragam pilihan ukuran dan warna dari BrayStyle.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main class="catalog-page page-shell">
    <div class="catalog-intro">
        <span class="eyebrow">TEMUKAN PILIHAN YANG PAS</span>
        <h1>Koleksi <span>cutbray</span></h1>
        <p>Silhouette flare yang ikonis, dibuat modern untuk gaya sehari-hari.</p>
    </div>

    <section id="kategori" class="catalog-toolbar" aria-label="Pencarian dan filter produk">
        <form class="catalog-search" action="produk.php" method="get" role="search">
            <label for="catalog-search-input">Cari koleksi</label>
            <div class="catalog-search-row">
                <input id="catalog-search-input" type="search" name="q" value="<?= catalogEscape($searchTerm) ?>" placeholder="Cari model atau warna cutbray">
                <?php if ($categoryId !== null): ?>
                    <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
                <?php endif; ?>
                <button class="button button-dark" type="submit">Cari <span aria-hidden="true">⌕</span></button>
            </div>
        </form>
        <div class="category-filters" aria-label="Filter kategori">
            <a class="filter-chip<?= $categoryId === null ? ' is-active' : '' ?>" href="produk.php<?= $searchTerm !== '' ? '?q=' . rawurlencode($searchTerm) : '' ?>">Semua</a>
            <?php foreach ($categories as $category): ?>
                <?php
                $filterQuery = ['category' => (int) $category['id']];
                if ($searchTerm !== '') {
                    $filterQuery['q'] = $searchTerm;
                }
                ?>
                <a class="filter-chip<?= $categoryId === (int) $category['id'] ? ' is-active' : '' ?>" href="produk.php?<?= http_build_query($filterQuery) ?>"><?= catalogEscape($category['name']) ?><span><?= (int) $category['product_count'] ?></span></a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="catalog-results" aria-labelledby="catalog-heading">
        <div class="catalog-results-heading">
            <h2 id="catalog-heading"><?= $searchTerm !== '' ? 'Hasil pencarian' : 'Semua pilihan' ?></h2>
            <span><?= count($products) ?> produk</span>
        </div>

        <?php if ($databaseError): ?>
            <div class="catalog-message" role="status">
                <span class="message-icon" aria-hidden="true">!</span>
                <div><strong>Koleksi belum dapat dimuat.</strong><p>Pastikan MySQL aktif dan database <code>tokocelana</code> sudah diimpor dari <code>database.sql</code>.</p></div>
            </div>
        <?php elseif ($products): ?>
            <div class="product-grid">
                <?php foreach ($products as $index => $product): ?>
                    <?php
                    $imageName = isset($product['image']) ? basename((string) $product['image']) : '';
                    $hasImage = $imageName !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $imageName);
                    $tone = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $product['category_slug']));
                    ?>
                    <article class="product-card">
                        <a class="product-visual product-tone-<?= catalogEscape($tone) ?>" href="detail.php?id=<?= (int) $product['id'] ?>" aria-label="Lihat <?= catalogEscape($product['name']) ?>">
                            <?php if ($hasImage): ?>
                                <img src="uploads/<?= rawurlencode($imageName) ?>" alt="<?= catalogEscape($product['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <span class="product-visual-label">BRAYSTYLE <span>· <?= sprintf('%02d', $index + 1) ?></span></span>
                                <svg class="pants-illustration" viewBox="0 0 180 230" aria-hidden="true"><path d="M45 24h90l-6 72 30 119h-53l-16-91-16 91H21L51 96z"></path><path class="pants-detail" d="M48 34h84M87 27v97m-33-22h21m48 0h-21"></path></svg>
                                <span class="visual-caption"><?= catalogEscape($product['category_name']) ?> / <?= sprintf('%02d', $index + 1) ?></span>
                            <?php endif; ?>
                            <span class="product-badge"><?= (int) $product['stock'] > 0 ? 'READY TO WEAR' : 'SEGERA HADIR' ?></span>
                        </a>
                        <div class="product-info">
                            <div class="product-category"><?= catalogEscape($product['category_name']) ?></div>
                            <h3><a href="detail.php?id=<?= (int) $product['id'] ?>"><?= catalogEscape($product['name']) ?></a></h3>
                            <p class="product-description"><?= catalogEscape($product['description']) ?></p>
                            <div class="product-bottom">
                                <span class="product-price">Rp <?= number_format((float) $product['price'], 0, ',', '.') ?></span>
                                <span class="product-stock"><?= (int) $product['stock'] ?> tersedia</span>
                            </div>
                            <div class="product-actions">
                                <a class="button button-outline" href="detail.php?id=<?= (int) $product['id'] ?>">Lihat Detail</a>
                                <?php if ((int) $product['stock'] > 0 && $product['default_variant_id'] !== null): ?>
                                    <form action="cart.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= catalogEscape(shopCsrfToken()) ?>">
                                        <input type="hidden" name="action" value="add">
                                        <input type="hidden" name="variant_id" value="<?= (int) $product['default_variant_id'] ?>">
                                        <input type="hidden" name="quantity" value="1">
                                        <button class="button button-add" type="submit">Beli sekarang <span aria-hidden="true">↗</span></button>
                                    </form>
                                <?php else: ?>
                                    <button class="button button-add" type="button" disabled>Stok habis</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <span class="empty-state-icon" aria-hidden="true">⌕</span>
                <h3>Produk belum ditemukan.</h3>
                <p>Coba kata kunci atau kategori lain untuk menemukan pilihanmu.</p>
                <a class="button button-dark" href="produk.php">Lihat semua produk</a>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
