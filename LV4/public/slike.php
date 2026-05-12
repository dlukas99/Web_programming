<?php
declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/auth.php";

function build_image_title(string $fileName): string
{
    $name = pathinfo($fileName, PATHINFO_FILENAME);
    $name = str_replace(["-", "_"], " ", $name);
    $name = trim($name);

    if ($name === "" || ctype_digit($name)) {
        return "Slika " . $name;
    }

    return ucwords($name);
}

$flash = get_flash();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "rate") {
        if (!is_logged_in()) {
            set_flash("error", "Morate biti prijavljeni za ocjenjivanje.");
            redirect("slike.php");
        }

        $imageId = (int)($_POST["image_id"] ?? 0);
        $rating = (int)($_POST["rating"] ?? 0);

        if ($imageId < 1 || $rating < 1 || $rating > 5) {
            set_flash("error", "Neispravna ocjena.");
            redirect("slike.php");
        }

        $stmt = $pdo->prepare(
            "INSERT INTO ratings (user_id, image_id, rating) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), rated_at = CURRENT_TIMESTAMP"
        );
        $stmt->execute([current_user()["id"], $imageId, $rating]);

        set_flash("success", "Ocjena je spremljena.");
        redirect("slike.php");
    }
}

$imagesDir = __DIR__ . "/images";
$allowedExtensions = ["jpg", "jpeg", "png", "gif", "webp"];

$existingStmt = $pdo->query("SELECT id, file_name FROM images");
$existingImages = [];
foreach ($existingStmt->fetchAll() as $row) {
    $existingImages[$row["file_name"]] = (int)$row["id"];
}

$insertStmt = $pdo->prepare("INSERT INTO images (file_name, title, path, source) VALUES (?, ?, ?, 'local')");

if (is_dir($imagesDir)) {
    $files = array_diff(scandir($imagesDir), [".", ".."]);
    foreach ($files as $file) {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExtensions, true)) {
            continue;
        }
        if (!isset($existingImages[$file])) {
            $title = build_image_title($file);
            $path = "images/" . $file;
            $insertStmt->execute([$file, $title, $path]);
        }
    }
}

$imagesStmt = $pdo->query(
    "SELECT i.id, i.title, i.path,
            COALESCE(AVG(r.rating), 0) AS avg_rating,
            COUNT(r.id) AS rating_count
     FROM images i
     LEFT JOIN ratings r ON r.image_id = i.id
     GROUP BY i.id
     ORDER BY i.id"
);
$images = $imagesStmt->fetchAll();

$userRatings = [];
if (is_logged_in()) {
    $ratingStmt = $pdo->prepare("SELECT image_id, rating FROM ratings WHERE user_id = ?");
    $ratingStmt->execute([current_user()["id"]]);
    foreach ($ratingStmt->fetchAll() as $row) {
        $userRatings[(int)$row["image_id"]] = (int)$row["rating"];
    }
}

$flashType = "";
$flashMessage = "";
if ($flash) {
    $flashType = in_array($flash["type"], ["success", "error", "warning"], true) ? $flash["type"] : "success";
    $flashMessage = $flash["message"];
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dinamička galerija slika - LV4 Web programiranje.">
    <link rel="stylesheet" href="style.css">
    <title>Music Hub - Galerija</title>
</head>
<body>

    <header>
        <nav aria-label="Glavna navigacija" class="hover-nav">
            <div class="menu-wrapper">
                <span class="menu-btn" role="button">Menu</span>
                <ul class="nav-links">
                    <li><a href="index.php">Početna</a></li>
                    <li><a href="grafikon.html">Analiza (Grafikon)</a></li>
                    <li><a href="slike.php">Galerija Slika</a></li>
                </ul>
            </div>
        </nav>
        <h1>🎵 Music Hub: Dinamička Galerija</h1>
    </header>

    <main class="grid-container" style="display: block;">
        <section class="table-section">
            <h2>Pregled instrumenata i opreme</h2>

            <?php if ($flashMessage !== "") { ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, "UTF-8"); ?>">
                    <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?>
                </div>
            <?php } ?>

            <div class="image-grid">
                <?php if (!empty($images)) { ?>
                    <?php foreach ($images as $image) { ?>
                        <figure class="image-card">
                            <a href="#<?php echo (int)$image["id"]; ?>-modal">
                                <img src="<?php echo htmlspecialchars($image["path"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($image["title"], ENT_QUOTES, "UTF-8"); ?>" class="responsive-img" style="height: 180px; object-fit: cover; cursor: pointer;">
                            </a>
                            <figcaption class="image-title">
                                <?php echo htmlspecialchars($image["title"], ENT_QUOTES, "UTF-8"); ?>
                            </figcaption>

                            <div class="rating-block">
                                <div class="rating-average">
                                    Prosjek: <?php echo htmlspecialchars(number_format((float)$image["avg_rating"], 1), ENT_QUOTES, "UTF-8"); ?>
                                    <span class="muted">(<?php echo (int)$image["rating_count"]; ?>)</span>
                                </div>

                                <?php if (is_logged_in()) { ?>
                                    <?php $currentRating = $userRatings[(int)$image["id"]] ?? 0; ?>
                                    <form method="post" class="rating-form">
                                        <input type="hidden" name="action" value="rate">
                                        <input type="hidden" name="image_id" value="<?php echo (int)$image["id"]; ?>">
                                        <div class="stars">
                                            <?php for ($i = 5; $i >= 1; $i--) { ?>
                                                <input
                                                    type="radio"
                                                    id="rate-<?php echo (int)$image["id"]; ?>-<?php echo $i; ?>"
                                                    name="rating"
                                                    value="<?php echo $i; ?>"
                                                    <?php echo $currentRating === $i ? "checked" : ""; ?>
                                                >
                                                <label for="rate-<?php echo (int)$image["id"]; ?>-<?php echo $i; ?>">★</label>
                                            <?php } ?>
                                        </div>
                                        <button class="button" type="submit">Spremi ocjenu</button>
                                    </form>
                                <?php } else { ?>
                                    <p class="muted">Prijavi se za ocjenjivanje.</p>
                                <?php } ?>
                            </div>
                        </figure>

                        <div id="<?php echo (int)$image["id"]; ?>-modal" class="lightbox">
                            <a href="#" class="lightbox-close">&times;</a>
                            <img src="<?php echo htmlspecialchars($image["path"], ENT_QUOTES, "UTF-8"); ?>" alt="<?php echo htmlspecialchars($image["title"], ENT_QUOTES, "UTF-8"); ?>" class="lightbox-image">
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="muted">Mapa sa slikama je prazna.</p>
                <?php } ?>
            </div>
        </section>
    </main>

    <footer>
        <p>&copy; 2026. Web Programiranje - Laboratorijska Vježba 4. Sva prava pridržana.</p>
    </footer>

</body>
</html>
