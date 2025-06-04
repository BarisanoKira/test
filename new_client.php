<?php
// Database connection parameters
$host = 'localhost';
$user = 'your_user';
$pass = 'your_password';
$db   = 'your_database';

// Connect to MySQL
$link = new mysqli($host, $user, $pass, $db);
if ($link->connect_errno) {
    die('Errore connessione MySQL: ' . $link->connect_error);
}

// Ensure lat/lon columns exist
$link->query("ALTER TABLE anagraficau ADD COLUMN IF NOT EXISTS lat DECIMAL(10,7)");
$link->query("ALTER TABLE anagraficau ADD COLUMN IF NOT EXISTS lon DECIMAL(10,7)");

$msg = '';
$success = false;

function geocode_address($address) {
    $opts = [
        'http' => [
            'header' => "User-Agent: clienti-app/1.0\r\n"
        ]
    ];
    $ctx = stream_context_create($opts);
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($address);
    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        return null;
    }
    $data = json_decode($response, true);
    if (!empty($data[0]['lat']) && !empty($data[0]['lon'])) {
        return [
            'lat' => floatval($data[0]['lat']),
            'lon' => floatval($data[0]['lon'])
        ];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Required fields
    $ints     = trim($_POST['ints'] ?? '');
    $via      = trim($_POST['via'] ?? '');
    $comune   = trim($_POST['comune'] ?? '');
    $provincia= trim($_POST['provincia'] ?? '');

    $n        = trim($_POST['n'] ?? '');
    $cell     = trim($_POST['cell'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if ($ints === '' || $via === '' || $comune === '' || $provincia === '') {
        $msg = 'Compilare tutti i campi obbligatori.';
    } elseif (!ctype_digit($ints)) {
        $msg = 'Il codice cliente deve essere numerico.';
    } else {
        // Check if client exists
        $stmt = $link->prepare('SELECT lat, lon FROM anagraficau WHERE ints = ?');
        $stmt->bind_param('s', $ints);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            // Client exists
            if (empty($row['lat']) || empty($row['lon'])) {
                $address = "$via $n, $comune, $provincia, Italy";
                $coords = geocode_address($address);
                if ($coords) {
                    $upd = $link->prepare('UPDATE anagraficau SET lat = ?, lon = ? WHERE ints = ?');
                    $upd->bind_param('dds', $coords['lat'], $coords['lon'], $ints);
                    $upd->execute();
                    $msg = 'Coordinate aggiornate per il cliente esistente.';
                    $success = true;
                } else {
                    $msg = 'Impossibile trovare le coordinate.';
                }
            } else {
                $msg = 'Cliente già presente.';
            }
        } else {
            // Insert new client
            $address = "$via $n, $comune, $provincia, Italy";
            $coords = geocode_address($address);
            if ($coords) {
                $ins = $link->prepare('INSERT INTO anagraficau (ints, via, n, comune, pr, cell, email, lat, lon) VALUES (?,?,?,?,?,?,?,?,?)');
                $ins->bind_param('sssssssss', $ints, $via, $n, $comune, $provincia, $cell, $email, $coords['lat'], $coords['lon']);
                if ($ins->execute()) {
                    $msg = 'Cliente inserito con successo.';
                    $success = true;
                } else {
                    $msg = 'Errore inserimento cliente.';
                }
            } else {
                $msg = 'Impossibile geocodificare l\'indirizzo.';
            }
        }
        $stmt->close();
    }
}

// Fetch all clients for listing
$clienti = [];
$res = $link->query('SELECT ints, via, n, comune, pr, cell, email, lat, lon FROM anagraficau');
while ($row = $res->fetch_assoc()) {
    $clienti[] = $row;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<title>Nuovo Cliente</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
<h2 class="mb-4">Inserisci Nuovo Cliente</h2>
<?php if($msg): ?>
<div class="alert <?php echo $success ? 'alert-success' : 'alert-danger'; ?>">
<?php echo htmlspecialchars($msg); ?>
</div>
<?php endif; ?>
<form method="post" class="row g-3">
  <div class="col-md-2">
    <label class="form-label">Codice Cliente*</label>
    <input type="text" name="ints" class="form-control" required>
  </div>
  <div class="col-md-4">
    <label class="form-label">Via*</label>
    <input type="text" name="via" class="form-control" required>
  </div>
  <div class="col-md-2">
    <label class="form-label">Numero Civico</label>
    <input type="text" name="n" class="form-control">
  </div>
  <div class="col-md-3">
    <label class="form-label">Comune*</label>
    <input type="text" name="comune" class="form-control" required>
  </div>
  <div class="col-md-1">
    <label class="form-label">Provincia*</label>
    <input type="text" name="provincia" class="form-control" required>
  </div>
  <div class="col-md-3">
    <label class="form-label">Cellulare</label>
    <input type="text" name="cell" class="form-control">
  </div>
  <div class="col-md-4">
    <label class="form-label">Email</label>
    <input type="email" name="email" class="form-control">
  </div>
  <div class="col-12">
    <button type="submit" class="btn btn-primary">Salva</button>
  </div>
</form>

<?php if(!empty($clienti)): ?>
<hr>
<h3>Clienti Registrati</h3>
<div class="table-responsive">
<table class="table table-striped">
<thead>
<tr>
<th>INTS</th><th>Via</th><th>N.</th><th>Comune</th><th>Prov.</th><th>Cell</th><th>Email</th><th>Lat</th><th>Lon</th>
</tr>
</thead>
<tbody>
<?php foreach($clienti as $c): ?>
<tr>
<td><?php echo htmlspecialchars($c['ints']); ?></td>
<td><?php echo htmlspecialchars($c['via']); ?></td>
<td><?php echo htmlspecialchars($c['n']); ?></td>
<td><?php echo htmlspecialchars($c['comune']); ?></td>
<td><?php echo htmlspecialchars($c['pr']); ?></td>
<td><?php echo htmlspecialchars($c['cell']); ?></td>
<td><?php echo htmlspecialchars($c['email']); ?></td>
<td><?php echo htmlspecialchars($c['lat']); ?></td>
<td><?php echo htmlspecialchars($c['lon']); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</body>
</html>
<?php
$link->close();
?>
