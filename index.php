<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';

function escapeHtml($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$searchTerm = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
$searchTerm = substr($searchTerm, 0, 100);
$categoryId = filter_input(INPUT_GET, 'category', FILTER_VALIDATE_INT);
$categoryId = $categoryId !== false && $categoryId !== null && $categoryId > 0 ? $categoryId : null;
$categories = [];
$products = [];
$databaseError = false;

try {
    $pdo = getDatabaseConnection();
    $categories = $pdo->query(
        'SELECT c.id, c.name, c.slug, COUNT(p.id) AS product_count
         FROM categories c
         LEFT JOIN products p ON p.category_id = c.id AND p.status = "active"
         GROUP BY c.id, c.name, c.slug
         ORDER BY c.id'
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
        $sql .= ' AND (p.name LIKE :search_name OR p.description LIKE :search_description OR c.name LIKE :search_category)';
        $searchPattern = '%' . $searchTerm . '%';
        $parameters[':search_name'] = $searchPattern;
        $parameters[':search_description'] = $searchPattern;
        $parameters[':search_category'] = $searchPattern;
    }

    $sql .= ' GROUP BY p.id, p.name, p.slug, p.description, p.price, p.image, c.name, c.slug
              ORDER BY p.created_at DESC, p.id DESC
              LIMIT 12';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $products = $statement->fetchAll();
} catch (PDOException $exception) {
    error_log('BrayStyle catalog error: ' . $exception->getMessage());
    $databaseError = true;
}

$pageTitle = 'BrayStyle';
$metaDescription = 'Temukan celana cutbray modern dengan potongan yang nyaman dan gaya minimalis dari BrayStyle.';
require __DIR__ . '/includes/header.php';
?>
<div id="top"></div>
<?php require __DIR__ . '/includes/navbar.php'; ?>

<main>
    <section class="hero page-shell" aria-labelledby="hero-title">
        <div class="hero-copy">
            <span class="eyebrow"><span class="eyebrow-dot"></span> BRAYSTYLE · CUTBRAY ESSENTIALS</span>
            <h1 id="hero-title">Temukan Gaya<br><span>Cutbray Pilihanmu.</span></h1>
            <p>Streetwear cutbray dengan siluet tegas, detail minimal, dan kenyamanan yang siap menemani langkahmu setiap hari.</p>
            <div class="hero-actions">
                <a class="button button-dark" href="produk.php">Belanja Sekarang <span aria-hidden="true">↗</span></a>
                <a class="text-link" href="#kategori">Kenali BrayStyle <span aria-hidden="true">↓</span></a>
            </div>
            <div class="hero-notes">
                <div><span class="note-icon" aria-hidden="true">✳</span><span>Cutbray modern<br><strong>Potongan berkarakter</strong></span></div>
                <div><span class="note-icon" aria-hidden="true">↗</span><span>Gaya minimalis<br><strong>Nyaman setiap hari</strong></span></div>
            </div>
        </div>
        <div class="hero-art" aria-label="Ilustrasi koleksi cutbray BrayStyle" role="img">
            <div class="hero-art-label"><span>THE CUTBRAY EDIT</span><span>01 / 05</span></div>
            <div class="hero-sun"></div>
            <div class="hero-pants hero-pants-back"></div>
            <div class="hero-pants hero-pants-front"></div>
            <div class="hero-stamp"><span>MADE<br>TO MOVE</span><span class="stamp-star">✳</span></div>
            <div class="hero-art-caption"><span>BRAYSTYLE<br>THE CUTBRAY FIT</span><span>DESIGNED<br>FOR EVERY DAY</span></div>
        </div>
    </section>

    <section class="trust-strip" aria-label="Keunggulan BrayStyle">
        <div class="page-shell trust-items">
            <span><i aria-hidden="true">✳</i> Silhouette cutbray khas</span>
            <span><i aria-hidden="true">✳</i> Bahan nyaman dipakai</span>
            <span><i aria-hidden="true">✳</i> Detail yang sederhana</span>
            <span><i aria-hidden="true">✳</i> Gaya untuk setiap hari</span>
        </div>
    </section>

    <section class="category-section page-shell" id="kategori" aria-labelledby="category-title">
        <div class="section-heading">
            <div>
                <span class="eyebrow">POTONGAN UNTUK GAYAMU</span>
                <h2 id="category-title">Pilih gaya cutbray favorit</h2>
            </div>
            <a class="text-link" href="produk.php">Lihat semua koleksi <span aria-hidden="true">↗</span></a>
        </div>
        <?php if ($categories): ?>
            <div class="category-grid">
                <?php foreach ($categories as $index => $category): ?>
                    <a class="category-card category-tone-<?= ($index % 5) + 1 ?>" href="produk.php?category=<?= (int) $category['id'] ?>#kategori">
                        <span class="category-index">0<?= $index + 1 ?></span>
                        <span class="category-card-name"><?= escapeHtml($category['name']) ?></span>
                        <span class="category-count"><?= (int) $category['product_count'] ?> produk <span aria-hidden="true">↗</span></span>
                        <span class="category-shape" aria-hidden="true"></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php elseif (!$databaseError): ?>
            <p class="empty-note">Kategori pilihan akan segera hadir.</p>
        <?php endif; ?>
    </section>

    <section class="collection-section" id="koleksi" aria-labelledby="collection-title">
        <div class="page-shell">
            <div class="section-heading collection-heading">
                <div>
                    <span class="eyebrow">PILIHAN BRAYSTYLE</span>
                    <h2 id="collection-title"><?= $searchTerm !== '' ? 'Hasil pencarian' : 'Cutbray pilihan' ?></h2>
                    <?php if ($searchTerm !== ''): ?>
                        <p class="result-copy">Menampilkan hasil untuk “<?= escapeHtml($searchTerm) ?>”</p>
                    <?php endif; ?>
                </div>
                <?php if ($categoryId !== null || $searchTerm !== ''): ?>
                    <a class="text-link" href="index.php#koleksi">Hapus filter <span aria-hidden="true">×</span></a>
                <?php else: ?>
                    <span class="collection-count"><?= count($products) ?> pilihan</span>
                <?php endif; ?>
            </div>

            <?php if ($databaseError): ?>
                <div class="catalog-message" role="status">
                    <span class="message-icon" aria-hidden="true">!</span>
                    <div>
                        <strong>Koleksi belum dapat dimuat.</strong>
                        <p>Pastikan MySQL aktif dan database <code>tokocelana</code> sudah diimpor dari <code>database.sql</code>, lalu muat ulang halaman ini.</p>
                    </div>
                </div>
            <?php elseif ($products): ?>
                <div class="product-grid">
                    <?php foreach ($products as $index => $product): ?>
                        <?php
                        $imageName = isset($product['image']) ? basename((string) $product['image']) : '';
                        $imagePath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $imageName;
                        $hasImage = $imageName !== '' && is_file($imagePath);
                        $tone = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $product['category_slug']));
                        ?>
                        <article class="product-card">
                            <a class="product-visual product-tone-<?= escapeHtml($tone) ?>" href="detail.php?id=<?= (int) $product['id'] ?>" aria-label="Lihat <?= escapeHtml($product['name']) ?>">
                                <?php if ($hasImage): ?>
                                    <img src="uploads/<?= rawurlencode($imageName) ?>" alt="<?= escapeHtml($product['name']) ?>" loading="lazy">
                                <?php else: ?>
                                    <span class="product-visual-label">BRAYSTYLE <span>· 0<?= $index + 1 ?></span></span>
                                    <svg class="pants-illustration" viewBox="0 0 180 230" aria-hidden="true">
                                        <path d="M45 24h90l-6 72 30 119h-53l-16-91-16 91H21L51 96z"></path>
                                        <path class="pants-detail" d="M48 34h84M87 27v97m-33-22h21m48 0h-21"></path>
                                    </svg>
                                    <span class="visual-caption"><?= escapeHtml($product['category_name']) ?> / 0<?= $index + 1 ?></span>
                                <?php endif; ?>
                                <span class="product-badge"><?= (int) $product['stock'] > 0 ? 'READY TO WEAR' : 'SEGERA HADIR' ?></span>
                            </a>
                            <div class="product-info">
                                <div class="product-category"><?= escapeHtml($product['category_name']) ?></div>
                                <h3><a href="detail.php?id=<?= (int) $product['id'] ?>"><?= escapeHtml($product['name']) ?></a></h3>
                                <p class="product-description"><?= escapeHtml($product['description']) ?></p>
                                <div class="product-bottom">
                                    <span class="product-price">Rp <?= number_format((float) $product['price'], 0, ',', '.') ?></span>
                                    <span class="product-stock"><?= (int) $product['stock'] ?> tersedia</span>
                                </div>
                                <div class="product-actions">
                                    <a class="button button-outline" href="detail.php?id=<?= (int) $product['id'] ?>">Lihat Detail</a>
                                    <?php if ((int) $product['stock'] > 0 && $product['default_variant_id'] !== null): ?>
                                        <form action="cart.php" method="post">
                                            <input type="hidden" name="csrf_token" value="<?= escapeHtml(shopCsrfToken()) ?>">
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
                    <h3>Belum ada yang cocok.</h3>
                    <p>Coba kata kunci lain atau lihat semua koleksi pilihan kami.</p>
                    <a class="button button-dark" href="index.php#koleksi">Lihat semua produk</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="closing-banner page-shell">
        <span class="closing-orbit" aria-hidden="true">✳</span>
        <div>
            <span class="eyebrow">STYLE SEDERHANA, PERCAYA DIRI LUAR BIASA</span>
                <h2>Cutbray yang terasa <em>seperti gayamu.</em></h2>
        </div>
        <a class="button button-light" href="#koleksi">Jelajahi pilihan <span aria-hidden="true">↗</span></a>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
