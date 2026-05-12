<?php
declare(strict_types=1);

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/auth.php";

function clean_string(string $value): string
{
    return trim($value);
}

function format_duration(int $seconds): string
{
    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    return sprintf("%d:%02d", $minutes, $remaining);
}

function mood_from_valence(float $valence): string
{
    if ($valence >= 0.66) {
        return "Pozitivno";
    }

    if ($valence >= 0.33) {
        return "Neutralno";
    }

    return "Tuzno";
}

$flash = get_flash();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";

    if ($action === "register") {
        $username = clean_string((string)($_POST["username"] ?? ""));
        $password = (string)($_POST["password"] ?? "");
        $confirm = (string)($_POST["confirm_password"] ?? "");

        $errors = [];
        if (strlen($username) < 3) {
            $errors[] = "Korisnicko ime mora imati barem 3 znaka.";
        }
        if (strlen($password) < 6) {
            $errors[] = "Lozinka mora imati barem 6 znakova.";
        }
        if ($password !== $confirm) {
            $errors[] = "Lozinke se ne podudaraju.";
        }

        if (empty($errors)) {
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $checkStmt->execute([$username]);
            if ($checkStmt->fetchColumn()) {
                $errors[] = "Korisnicko ime je zauzeto.";
            }
        }

        if (!empty($errors)) {
            set_flash("error", implode(" ", $errors));
            redirect("index.php");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $insertStmt->execute([$username, $hash]);

        set_flash("success", "Uspjesna registracija. Sada se mozete prijaviti.");
        redirect("index.php");
    }

    if ($action === "login") {
        $username = clean_string((string)($_POST["username"] ?? ""));
        $password = (string)($_POST["password"] ?? "");

        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user["password_hash"])) {
            set_flash("error", "Neispravno korisnicko ime ili lozinka.");
            redirect("index.php");
        }

        login_user([
            "id" => (int)$user["id"],
            "username" => $user["username"],
        ]);

        set_flash("success", "Prijava uspjesna.");
        redirect("index.php");
    }

    if ($action === "add_song") {
        if (!is_logged_in()) {
            set_flash("error", "Morate biti prijavljeni za unos pjesme.");
            redirect("index.php");
        }

        $title = clean_string((string)($_POST["title"] ?? ""));
        $artist = clean_string((string)($_POST["artist"] ?? ""));
        $genre = clean_string((string)($_POST["genre"] ?? ""));
        $duration = (int)($_POST["duration_seconds"] ?? 0);
        $bpm = (int)($_POST["bpm"] ?? 0);
        $year = (int)($_POST["release_year"] ?? 0);
        $mood = clean_string((string)($_POST["mood"] ?? ""));

        $errors = [];
        $currentYear = (int)date("Y");

        if ($title === "" || $artist === "" || $genre === "" || $mood === "") {
            $errors[] = "Sva polja su obavezna.";
        }
        if ($duration < 30 || $duration > 3600) {
            $errors[] = "Trajanje mora biti izmedu 30 i 3600 sekundi.";
        }
        if ($bpm < 40 || $bpm > 240) {
            $errors[] = "BPM mora biti izmedu 40 i 240.";
        }
        if ($year < 1900 || $year > $currentYear) {
            $errors[] = "Godina mora biti izmedu 1900 i {$currentYear}.";
        }

        if (!empty($errors)) {
            set_flash("error", implode(" ", $errors));
            redirect("index.php");
        }

        $insertStmt = $pdo->prepare(
            "INSERT INTO songs (title, artist, genre, duration_seconds, bpm, release_year, mood, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insertStmt->execute([
            $title,
            $artist,
            $genre,
            $duration,
            $bpm,
            $year,
            $mood,
            current_user()["id"],
        ]);

        set_flash("success", "Pjesma je dodana.");
        redirect("index.php");
    }

    if ($action === "add_to_list") {
        if (!is_logged_in()) {
            set_flash("error", "Morate biti prijavljeni za dodavanje u osobnu listu.");
            redirect("index.php");
        }

        $songId = (int)($_POST["song_id"] ?? 0);
        if ($songId < 1) {
            set_flash("error", "Neispravan odabir pjesme.");
            redirect("index.php");
        }

        $checkStmt = $pdo->prepare("SELECT 1 FROM personal_list WHERE user_id = ? AND song_id = ?");
        $checkStmt->execute([current_user()["id"], $songId]);

        if ($checkStmt->fetchColumn()) {
            set_flash("warning", "Pjesma je vec na osobnoj listi.");
            redirect("index.php");
        }

        $insertStmt = $pdo->prepare("INSERT INTO personal_list (user_id, song_id) VALUES (?, ?)");
        $insertStmt->execute([current_user()["id"], $songId]);

        set_flash("success", "Pjesma dodana na osobnu listu.");
        redirect("index.php");
    }

    if ($action === "remove_from_list") {
        if (!is_logged_in()) {
            set_flash("error", "Morate biti prijavljeni.");
            redirect("index.php");
        }

        $songId = (int)($_POST["song_id"] ?? 0);
        $deleteStmt = $pdo->prepare("DELETE FROM personal_list WHERE user_id = ? AND song_id = ?");
        $deleteStmt->execute([current_user()["id"], $songId]);

        set_flash("success", "Pjesma uklonjena s liste.");
        redirect("index.php");
    }

    if ($action === "import_csv") {
        if (!is_logged_in()) {
            set_flash("error", "Morate biti prijavljeni za uvoz.");
            redirect("index.php");
        }

        if (!isset($_FILES["csv_file"]) || $_FILES["csv_file"]["error"] !== UPLOAD_ERR_OK) {
            set_flash("error", "CSV datoteka nije ucitana.");
            redirect("index.php");
        }

        $fileName = (string)$_FILES["csv_file"]["name"];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== "csv") {
            set_flash("error", "Dozvoljen je samo CSV format.");
            redirect("index.php");
        }

        $handle = fopen($_FILES["csv_file"]["tmp_name"], "r");
        if ($handle === false) {
            set_flash("error", "CSV datoteka se ne moze otvoriti.");
            redirect("index.php");
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            set_flash("error", "CSV datoteka je prazna.");
            redirect("index.php");
        }

        $map = array_flip($header);
        $isLocal = isset($map["Naslov"], $map["Izvođač"], $map["Godina"]);
        $requiredColumns = $isLocal
            ? ["Naslov", "Izvođač", "Godina", "BPM", "Trajanje"]
            : ["name", "artist", "year", "duration_ms", "tempo"];

        foreach ($requiredColumns as $column) {
            if (!array_key_exists($column, $map)) {
                fclose($handle);
                set_flash("error", "CSV nema stupac: {$column}.");
                redirect("index.php");
            }
        }

        $pdo->beginTransaction();
        $insertStmt = $pdo->prepare(
            "INSERT INTO songs (title, artist, genre, duration_seconds, bpm, release_year, mood, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $checkStmt = $pdo->prepare("SELECT id FROM songs WHERE title = ? AND artist = ? AND release_year = ? LIMIT 1");

        $inserted = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if ($isLocal) {
                $title = clean_string((string)($row[$map["Naslov"]] ?? ""));
                $artist = clean_string((string)($row[$map["Izvođač"]] ?? ""));
                $yearRaw = clean_string((string)($row[$map["Godina"]] ?? ""));
                $durationSeconds = (int)($row[$map["Trajanje"]] ?? 0);
                $bpm = (int)($row[$map["BPM"]] ?? 0);
                $genre = clean_string((string)($row[$map["Žanr"]] ?? ""));
                $mood = clean_string((string)($row[$map["Raspoloženje"]] ?? ""));
            } else {
                $title = clean_string((string)($row[$map["name"]] ?? ""));
                $artist = clean_string((string)($row[$map["artist"]] ?? ""));
                $yearRaw = clean_string((string)($row[$map["year"]] ?? ""));
                $durationMs = (int)($row[$map["duration_ms"]] ?? 0);
                $tempo = (float)($row[$map["tempo"]] ?? 0);
                $durationSeconds = (int)round($durationMs / 1000);
                $bpm = (int)round($tempo);
                $genre = clean_string((string)($row[$map["genre"]] ?? ""));
                $mood = "";
            }

            if ($title === "" || $artist === "" || $yearRaw === "") {
                $skipped++;
                continue;
            }

            $date = DateTime::createFromFormat("Y", $yearRaw);
            if (!$date) {
                $skipped++;
                continue;
            }

            $year = (int)$date->format("Y");

            if ($durationSeconds < 30) {
                $skipped++;
                continue;
            }

            if ($genre === "") {
                $tags = isset($map["tags"]) ? clean_string((string)($row[$map["tags"]] ?? "")) : "";
                if ($tags !== "") {
                    $parts = array_filter(array_map("trim", explode(",", $tags)));
                    $genre = $parts[0] ?? "";
                }
            }
            if ($genre === "") {
                $genre = "Nepoznato";
            }

            if ($mood === "") {
                $valence = isset($map["valence"]) ? (float)($row[$map["valence"]] ?? 0) : 0.0;
                $mood = mood_from_valence($valence);
            }

            $checkStmt->execute([$title, $artist, $year]);
            if ($checkStmt->fetchColumn()) {
                $skipped++;
                continue;
            }

            $insertStmt->execute([
                $title,
                $artist,
                $genre,
                max(30, $durationSeconds),
                max(40, min(240, $bpm)),
                $year,
                $mood,
                current_user()["id"],
            ]);
            $inserted++;
        }

        $pdo->commit();
        fclose($handle);

        set_flash("success", "Uvoz zavrsen. Dodano: {$inserted}, preskoceno: {$skipped}.");
        redirect("index.php");
    }
}

$filters = [
    "artist" => clean_string((string)($_GET["artist"] ?? "")),
    "genre" => clean_string((string)($_GET["genre"] ?? "")),
    "release_year" => clean_string((string)($_GET["release_year"] ?? "")),
    "mood" => clean_string((string)($_GET["mood"] ?? "")),
    "bpm_min" => clean_string((string)($_GET["bpm_min"] ?? "")),
    "bpm_max" => clean_string((string)($_GET["bpm_max"] ?? "")),
];

$where = [];
$params = [];

if ($filters["artist"] !== "") {
    $where[] = "artist LIKE ?";
    $params[] = "%" . $filters["artist"] . "%";
}
if ($filters["genre"] !== "") {
    $where[] = "genre LIKE ?";
    $params[] = "%" . $filters["genre"] . "%";
}
if ($filters["release_year"] !== "" && ctype_digit($filters["release_year"])) {
    $where[] = "release_year = ?";
    $params[] = (int)$filters["release_year"];
}
if ($filters["mood"] !== "") {
    $where[] = "mood LIKE ?";
    $params[] = "%" . $filters["mood"] . "%";
}
if ($filters["bpm_min"] !== "" && ctype_digit($filters["bpm_min"])) {
    $where[] = "bpm >= ?";
    $params[] = (int)$filters["bpm_min"];
}
if ($filters["bpm_max"] !== "" && ctype_digit($filters["bpm_max"])) {
    $where[] = "bpm <= ?";
    $params[] = (int)$filters["bpm_max"];
}

$sql = "SELECT id, title, artist, genre, duration_seconds, bpm, release_year, mood FROM songs";
if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}
$sql .= " ORDER BY release_year DESC, title ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$songs = $stmt->fetchAll();

$personalList = [];
if (is_logged_in()) {
    $listStmt = $pdo->prepare(
        "SELECT s.id, s.title, s.artist, s.genre, s.duration_seconds, s.bpm, s.release_year, s.mood
         FROM personal_list pl
         INNER JOIN songs s ON s.id = pl.song_id
         WHERE pl.user_id = ?
         ORDER BY pl.created_at DESC"
    );
    $listStmt->execute([current_user()["id"]]);
    $personalList = $listStmt->fetchAll();
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
    <meta name="description" content="Pregled statistike najpopularnijih rock i indie pjesama - LV4 Web programiranje.">
    <link rel="stylesheet" href="style.css">
    <title>Music Hub - Popis Pjesama</title>
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
        <img src="logo.png" alt="Lukasfy logo" class="site-logo">
    </header>

    <main class="grid-container">
        <section class="table-section" aria-labelledby="naslov-tablice">
            <h2 id="naslov-tablice">Popis pjesama iz baze</h2>

            <?php if ($flashMessage !== "") { ?>
                <div class="alert alert-<?php echo htmlspecialchars($flashType, ENT_QUOTES, "UTF-8"); ?>">
                    <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?>
                </div>
            <?php } ?>

            <div class="form-card">
                <h3>Filtriranje pjesama</h3>
                <form method="get" class="form-grid">
                    <label>
                        Izvođač
                        <input class="input" type="text" name="artist" value="<?php echo htmlspecialchars($filters["artist"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <label>
                        Žanr
                        <input class="input" type="text" name="genre" value="<?php echo htmlspecialchars($filters["genre"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <label>
                        Godina
                        <input class="input" type="number" name="release_year" value="<?php echo htmlspecialchars($filters["release_year"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <label>
                        Raspoloženje
                        <input class="input" type="text" name="mood" value="<?php echo htmlspecialchars($filters["mood"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <label>
                        BPM min
                        <input class="input" type="number" name="bpm_min" value="<?php echo htmlspecialchars($filters["bpm_min"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <label>
                        BPM max
                        <input class="input" type="number" name="bpm_max" value="<?php echo htmlspecialchars($filters["bpm_max"], ENT_QUOTES, "UTF-8"); ?>">
                    </label>
                    <div class="form-actions">
                        <button class="button" type="submit">Filtriraj</button>
                        <a class="button button-ghost" href="index.php">Reset</a>
                    </div>
                </form>
            </div>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Naziv pjesme</th>
                            <th>Izvođač</th>
                            <th>Žanr</th>
                            <th>Godina</th>
                            <th>Tempo (BPM)</th>
                            <th>Trajanje</th>
                            <th>Raspoloženje</th>
                            <th>Akcija</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($songs)) { ?>
                            <?php foreach ($songs as $song) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($song["title"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars($song["artist"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars($song["genre"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars((string)$song["release_year"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars((string)$song["bpm"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars(format_duration((int)$song["duration_seconds"]), ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars($song["mood"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td>
                                        <?php if (is_logged_in()) { ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="add_to_list">
                                                <input type="hidden" name="song_id" value="<?php echo (int)$song["id"]; ?>">
                                                <button class="button" type="submit">Dodaj</button>
                                            </form>
                                        <?php } else { ?>
                                            <span class="muted">Prijavi se</span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        <?php } else { ?>
                            <tr>
                                <td colspan="8" class="muted">Nema rezultata za zadane filtre.</td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <?php if (is_logged_in()) { ?>
                <div class="form-card">
                    <h3>Unos nove pjesme</h3>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="add_song">
                        <label>
                            Naziv
                            <input class="input" type="text" name="title" required>
                        </label>
                        <label>
                            Izvođač
                            <input class="input" type="text" name="artist" required>
                        </label>
                        <label>
                            Žanr
                            <input class="input" type="text" name="genre" required>
                        </label>
                        <label>
                            Trajanje (sek)
                            <input class="input" type="number" name="duration_seconds" min="30" max="3600" required>
                        </label>
                        <label>
                            BPM
                            <input class="input" type="number" name="bpm" min="40" max="240" required>
                        </label>
                        <label>
                            Godina
                            <input class="input" type="number" name="release_year" min="1900" max="<?php echo (int)date("Y"); ?>" required>
                        </label>
                        <label>
                            Raspoloženje
                            <input class="input" type="text" name="mood" required>
                        </label>
                        <div class="form-actions">
                            <button class="button" type="submit">Spremi pjesmu</button>
                        </div>
                    </form>
                </div>

                <div class="form-card">
                    <h3>Uvoz pjesama iz CSV-a</h3>
                    <form method="post" enctype="multipart/form-data" class="form-grid">
                        <input type="hidden" name="action" value="import_csv">
                        <label>
                            CSV datoteka
                            <input class="input" type="file" name="csv_file" accept=".csv" required>
                        </label>
                        <div class="form-actions">
                            <button class="button" type="submit">Uvezi</button>
                        </div>
                    </form>
                    <p class="muted">Ocekivani stupci: name, artist, year, duration_ms, tempo (opcionalno genre, tags, valence) ili Naslov, Izvođač, Godina, Trajanje, BPM (opcionalno Žanr, Raspoloženje).</p>
                </div>
            <?php } ?>

            <?php if (is_logged_in()) { ?>
                <div class="form-card">
                    <h3>Moja osobna lista</h3>
                    <?php if (!empty($personalList)) { ?>
                        <div class="scroll-box">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Naziv</th>
                                        <th>Izvođač</th>
                                        <th>Žanr</th>
                                        <th>Godina</th>
                                        <th>BPM</th>
                                        <th>Trajanje</th>
                                        <th>Raspoloženje</th>
                                        <th>Akcija</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($personalList as $song) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($song["title"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($song["artist"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($song["genre"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars((string)$song["release_year"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars((string)$song["bpm"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars(format_duration((int)$song["duration_seconds"]), ENT_QUOTES, "UTF-8"); ?></td>
                                            <td><?php echo htmlspecialchars($song["mood"], ENT_QUOTES, "UTF-8"); ?></td>
                                            <td>
                                                <form method="post">
                                                    <input type="hidden" name="action" value="remove_from_list">
                                                    <input type="hidden" name="song_id" value="<?php echo (int)$song["id"]; ?>">
                                                    <button class="button button-ghost" type="submit">Ukloni</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    <?php } else { ?>
                        <p class="muted">Jos nemate pjesama na listi.</p>
                    <?php } ?>
                </div>
            <?php } ?>
        </section>

        <aside class="sidebar">
            <?php if (!is_logged_in()) { ?>
                <div class="form-card">
                    <h3>Prijava</h3>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="login">
                        <label>
                            Korisničko ime
                            <input class="input" type="text" name="username" required>
                        </label>
                        <label>
                            Lozinka
                            <input class="input" type="password" name="password" required>
                        </label>
                        <div class="form-actions">
                            <button class="button" type="submit">Prijava</button>
                        </div>
                    </form>
                </div>

                <div class="form-card">
                    <h3>Registracija</h3>
                    <form method="post" class="form-grid">
                        <input type="hidden" name="action" value="register">
                        <label>
                            Korisničko ime
                            <input class="input" type="text" name="username" required>
                        </label>
                        <label>
                            Lozinka
                            <input class="input" type="password" name="password" required>
                        </label>
                        <label>
                            Potvrda lozinke
                            <input class="input" type="password" name="confirm_password" required>
                        </label>
                        <div class="form-actions">
                            <button class="button" type="submit">Registracija</button>
                        </div>
                    </form>
                </div>
            <?php } else { ?>
                <div class="form-card">
                    <h3>Dobrodošli, <?php echo htmlspecialchars(current_user()["username"], ENT_QUOTES, "UTF-8"); ?></h3>
                    <p class="muted">Prijavljeni ste u sustav.</p>
                    <a class="button" href="logout.php">Odjava</a>
                </div>
            <?php } ?>

            <h3 id="aside-naslov">Istaknuti Album</h3>
            <picture>
                <source media="(max-width: 600px)" srcset="https://images.unsplash.com/photo-1493225255756-d9584f8606e9?w=400">
                <img src="https://images.unsplash.com/photo-1493225255756-d9584f8606e9?w=800" 
                     alt="Slika gitare i glazbene opreme u studiju" 
                     class="responsive-img"
                     aria-labelledby="aside-naslov">
            </picture>
            <p>Glazba se stalno mijenja, ali dobri klasici ostaju vječni.</p>
        </aside>
    </main>

    <article class="news-article">
        <h2>Zanimljivost o BPM-u</h2>
        <p>Tempo ili BPM (otkucaji u minuti) direktno utječe na to kako doživljavamo energiju pjesme.</p>
    </article>

    <footer>
        <p>&copy; 2026. Web Programiranje - Laboratorijska Vježba 4. Sva prava pridržana.</p>
    </footer>

</body>
</html>
