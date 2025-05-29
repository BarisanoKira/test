<?php
session_start();
include "utente.php";
include "config.php";
include "utenti_autorizzati.php";


// Debug (disabilita in produzione)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);


// Ad esempio, se la variabile $utente_autorizzato (definita in utenti_autorizzati.php) non esiste o è falsa:
if (!isset($utente_autorizzato) || !$utente_autorizzato) {
  header("Location: https://www.kira-group.it/config/log.php");
  exit();
}

date_default_timezone_set('Europe/Rome');

// 1) Parametri: anno selezionato
$anno_corrente    = date('Y');
$anno_selezionato = isset($_GET['anno']) ? (int)$_GET['anno'] : $anno_corrente;

// Anni da mostrare: anno-2, anno-1, anno
$anni_da_mostrare = [
    $anno_selezionato - 2,
    $anno_selezionato - 1,
    $anno_selezionato
];

// Intervallo per l'anno selezionato (per "attivi" e "stato")
$rangeSelected_start = "$anno_selezionato-01-01";
$rangeSelected_end   = "$anno_selezionato-12-31";

// Intervallo globale per i 3 anni: da (anno-2)-01-01 a (anno)-12-31
$range_start = "{$anni_da_mostrare[0]}-01-01";
$range_end   = "{$anni_da_mostrare[2]}-12-31";

// 2) Calcolo Clienti Attivi (quelli che hanno acquistato nell'anno selezionato)
$sql_inrange = "
    SELECT DISTINCT richiedente
    FROM acquisti
    WHERE confermato IN ('Si','Si promo','Si_promozionale')
      AND conf BETWEEN ? AND ?
";
$stmt_in = $link->prepare($sql_inrange);
$stmt_in->bind_param("ss", $rangeSelected_start, $rangeSelected_end);
$stmt_in->execute();
$res_in = $stmt_in->get_result();
$purchasedInRange = [];
while($rowIN = $res_in->fetch_assoc()){
    $purchasedInRange[] = $rowIN['richiedente'];
}
$stmt_in->close();
$numAttiviRange = count($purchasedInRange);

// 3) Stato Cliente per l'anno selezionato
$sql_stato = "
    SELECT richiedente,
           SUM(CASE WHEN confermato IN ('Si','Si promo','Si_promozionale') THEN 1 ELSE 0 END) AS num_conf,
           SUM(CASE WHEN confermato IN ('Annullato','Sviluppato') THEN 1 ELSE 0 END) AS num_prev
    FROM acquisti
    WHERE conf BETWEEN ? AND ?
    GROUP BY richiedente
";
$stmt_st = $link->prepare($sql_stato);
$stmt_st->bind_param("ss", $rangeSelected_start, $rangeSelected_end);
$stmt_st->execute();
$res_st = $stmt_st->get_result();
$statusMap = [];
while($rowS = $res_st->fetch_assoc()){
    $r = $rowS['richiedente'];
    $statusMap[$r] = [
        'num_conf' => (int)$rowS['num_conf'],
        'num_prev' => (int)$rowS['num_prev']
    ];
}
$stmt_st->close();

// 4) Acquisito per i 3 anni (LEFT JOIN come in reportagente.php)
$anni_condition = implode(", ", $anni_da_mostrare);
$sql_acq = "
    SELECT 
        a.ints AS richiedente,
        YEAR(ac.conf) AS anno,
        ROUND(SUM(IFNULL(ac.importo, 0)), 2) AS somma
    FROM anagraficau a
    LEFT JOIN acquisti ac 
        ON a.ints = ac.richiedente
        AND YEAR(ac.conf) IN ($anni_condition)
        AND ac.confermato IN ('Si', 'Si promo', 'Si_promozionale')
    GROUP BY a.ints, YEAR(ac.conf)
";
$res_ac = mysqli_query($link, $sql_acq);
$acquistiMap = [];
while($rowA = mysqli_fetch_assoc($res_ac)){
    $rich = $rowA['richiedente'];
    $anno = (int)$rowA['anno'];
    $somma = (float)$rowA['somma'];
    if(!isset($acquistiMap[$rich])){
        $acquistiMap[$rich] = [];
    }
    $acquistiMap[$rich][$anno] = $somma;
}
mysqli_free_result($res_ac);

// 5) Determinazione dei Clienti Nuovi (per la CARD)
// Utilizziamo una query che, per ogni cliente, restituisce la data della prima vendita
$sql_first = "
    SELECT richiedente, MIN(conf) AS first_purchase
    FROM acquisti
    WHERE confermato IN ('Si','Si promo','Si_promozionale')
    GROUP BY richiedente
";
$res_first = mysqli_query($link, $sql_first);
$newClientsByDate = [];
while($row = mysqli_fetch_assoc($res_first)){
    $newClientsByDate[$row['richiedente']] = $row['first_purchase'];
}
mysqli_free_result($res_first);

// 6) Lettura anagraficau e costruzione dell'array dei clienti
// Aggiorniamo la query per includere anche il campo log
$sql_ana = "
   SELECT ints, comune, pr, tell, cell, email, agente, log
   FROM anagraficau
";
$res_ana = mysqli_query($link, $sql_ana);
$clienti = [];
$provinceSet = [];
while($row = mysqli_fetch_assoc($res_ana)){
    $ints = $row['ints'];
    
    // Stato (basato sull'anno selezionato)
    $num_conf = $statusMap[$ints]['num_conf'] ?? 0;
    $num_prev = $statusMap[$ints]['num_prev'] ?? 0;
    if($num_conf > 0){
        $stato = 'Attivo';
    } elseif($num_prev > 0){
        $stato = 'Preventivi';
    } else {
        $stato = 'Non attivo';
    }
    
    // Acquisiti per ciascuno dei 3 anni
    $anniData = [];
    foreach($anni_da_mostrare as $yy){
        $anniData[$yy] = $acquistiMap[$ints][$yy] ?? 0;
    }
    
    if (!function_exists('normalizeProvince')) {
      function normalizeProvince($input) {
          // Converte in uppercase e rimuove spazi
          $p = strtoupper(trim($input));
          // Rimuove eventuali caratteri non alfabetici (ad es. punti, spazi, simboli)
          $p_clean = preg_replace('/[^A-Z]/', '', $p);
          
          // Mappatura per unificare i nomi delle province italiane
          $mapping = [

          ];
          if(isset($mapping[$p_clean])){
              return $mapping[$p_clean];
          }
          return $p_clean;
      }
  }
    $normPr = normalizeProvince($row['pr'] ?? '');
    if($normPr !== '' && !in_array($normPr, $provinceSet)){
        $provinceSet[] = $normPr;
    }
    
    // Cliente nuovo per la CARD: solo se ha acquistato e la sua prima vendita è >= rangeSelected_start
    $isNuovo = (isset($newClientsByDate[$ints]) && $newClientsByDate[$ints] >= $rangeSelected_start) ? 1 : 0;
    
    // Cliente "inserito" nell'anno corrente: usiamo il campo log (assumiamo log sia un timestamp)
    $logVal = isset($row['log']) ? (int)$row['log'] : 0;
    $isInserted = (date("Y", $logVal) == $anno_selezionato) ? 1 : 0;
    
    $clienti[] = [
        'ints'      => $ints,
        'stato'     => $stato,
        'agente'    => $row['agente'] ?? '',
        'comune'    => $row['comune'] ?? '',
        'pr'        => $normPr,
        'tell'      => $row['tell'] ?? '',
        'cell'      => $row['cell'] ?? '',
        'email'     => $row['email'] ?? '',
        'acquisiti' => $anniData,
        'isNuovo'   => $isNuovo,      // per la CARD (conta solo chi ha acquistato)
        'isInserted'=> $isInserted    // per la select: mostra tutti i record inseriti nell'anno
    ];
}
mysqli_free_result($res_ana);

sort($provinceSet, SORT_STRING);

// Calcola il numero di nuovi clienti (per la CARD: solo chi ha acquistato e risultano nuovi)
$numNuoviClienti = 0;
foreach ($clienti as $c) {
    if ($c['isNuovo'] == 1) {
        $numNuoviClienti++;
    }
}

// Ordinamento di default: discendente in base all'acquisito dell'anno selezionato
usort($clienti, function($a, $b) use($anno_selezionato) {
    $va = $a['acquisiti'][$anno_selezionato] ?? 0;
    $vb = $b['acquisiti'][$anno_selezionato] ?? 0;
    return $vb <=> $va;
});
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <title>Clienti</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Icone -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">


  <link rel="stylesheet" href="styleReport.css">

  <style>

    .stato-pill {
      display:inline-block;
      padding:3px 8px;
      border-radius:12px;
      color:#fff;
      font-size:0.9em;
      white-space:nowrap;
    }
    .stato-attivo { background-color:#28a745; }
    .stato-preventivi { background-color:#f6c23e; color:#212529; }
    .stato-nonattivo { background-color:#dc3545; }
    .new-label {
      font-size:0.75em;
      color:#0d6efd;
      margin-left:5px;
      font-weight:600;
    }
    .arrow-sort { color:#0d6efd; }
    .arrow-up { color:#28a745; margin-left:4px; }
    .arrow-down { color:#dc3545; margin-left:4px; }
    .card { overflow:hidden; }
  </style>
</head>
<body>



<!-- ====================== MODAL SELEZIONA AGENTI ====================== -->
<?php if($utente_autorizzato): ?>
<div class="modal fade" id="selezionaAgentiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Seleziona Agente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div id="searchContainer">
                    <input type="text"
                           class="form-control mb-3"
                           id="searchAgente"
                           placeholder="Cerca agente..."
                           oninput="filterAgenti()">
                </div>

                <div style="display:flex;justify-content:center;">
                    <div id="tableAgenti">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Agente</th>
                                    <th style="width:100px;">Seleziona</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Esempio: elenco agenti da tabella "agente"
                                $sql_ag = "SELECT * FROM agente";
                                $res_ag = mysqli_query($link, $sql_ag);
                                while($ra = mysqli_fetch_assoc($res_ag)):
                                    $nome_ag = $ra['cognome']."_".$ra['nome'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($nome_ag); ?></td>
                                    <td>
                                      <button class="btn btn-sm btn-primary"
                                              onclick="selectAgente('<?php echo $nome_ag; ?>', 'reportagente.php')">
                                            Seleziona
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div> <!-- end #tableAgenti -->
                </div>

            </div> <!-- end .modal-body -->
        </div> <!-- end .modal-content -->
    </div> <!-- end .modal-dialog -->
</div> <!-- end #selezionaAgentiModal -->
<?php endif; ?>


<!-- ====================== MODAL IMPOSTAZIONI ====================== -->
<?php if($utente_autorizzato): ?>
<div class="modal fade" id="impostazioniModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Impostazioni Dashboard</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form method="post" action="save_cards_settings.php?anno=<?php echo $anno_selezionato; ?>">
            <input type="hidden" name="anno" value="<?php echo $anno_selezionato; ?>">

            <table class="table">
                <thead>
                    <tr>
                      <th>Card</th>
                      <th>Visibile</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                      <td>Totale acquisito</td>
                      <td>
                        <input type="checkbox"
                               name="donutAcquisito"
                               class="form-check-input"
                               <?php if($cards_visible['donutAcquisito']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Totale non confermato</td>
                      <td>
                        <input type="checkbox"
                               name="donutNonConf"
                               class="form-check-input"
                               <?php if($cards_visible['donutNonConf']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Totale commesse confermate</td>
                      <td>
                        <input type="checkbox"
                               name="donutCommesse"
                               class="form-check-input"
                               <?php if($cards_visible['donutCommesse']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Obiettivo</td>
                      <td>
                        <input type="checkbox"
                               name="donutObiettivo"
                               class="form-check-input"
                               <?php if($cards_visible['donutObiettivo']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Andamento mensile ordini confermati</td>
                      <td>
                        <input type="checkbox"
                               name="chartOrdiniConfermati"
                               class="form-check-input"
                               <?php if($cards_visible['chartOrdiniConfermati']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Scelta commessa</td>
                      <td>
                        <input type="checkbox"
                               name="chartSceltaCommessa"
                               class="form-check-input"
                               <?php if($cards_visible['chartSceltaCommessa']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Acquisito Mensile (confronto)</td>
                      <td>
                        <input type="checkbox"
                               name="chartFatturatoConfronto"
                               class="form-check-input"
                               <?php if($cards_visible['chartFatturatoConfronto']) echo 'checked';?>>
                      </td>
                    </tr>
                    <tr>
                      <td>Pezzi Mensili per Prodotto</td>
                      <td>
                        <input type="checkbox"
                               name="chartProdotti"
                               class="form-check-input"
                               <?php if($cards_visible['chartProdotti']) echo 'checked';?>>
                      </td>
                    </tr>
                </tbody>
            </table>

            <button type="submit" class="btn btn-primary">Salva</button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<!-- ====================== FINE MODAL IMPOSTAZIONI ====================== -->




<div class="d-flex">
  <!-- ====================== INIZIO SIDEBAR ====================== -->
  <div class="sidebar p-3 d-flex flex-column" style="height:100vh;">
      <div class="sidebar-header mb-4">
          <h5 class="text-white">Dashboard</h5>
      </div>
      <ul class="nav flex-column">
          <li class="nav-item">
              <a class="nav-link" href="https://www.kira-group.it/config/log.php">
                  <i class="fas fa-home me-2"></i> Home
              </a>
          </li>

          <?php if($utente_autorizzato): ?>
          <li class="nav-item">
              <a class="nav-link" href="report_azienda.php">
                  <i class="fas fa-chart-line me-2"></i> Report Aziendale
              </a>
          </li>
          <?php endif; ?>

          <?php if($utente_autorizzato): ?>
          <!-- Pulsante per aprire il modale di selezione agenti -->
          <li class="nav-item">
              <a class="nav-link"
                href="#"
                data-bs-toggle="modal"
                data-bs-target="#selezionaAgentiModal">
                  <i class="fas fa-users me-2"></i> Agenti
              </a>
          </li>
          <?php endif; ?>

          <?php if($utente_autorizzato): ?>
          <li class="nav-item">
              <a class="nav-link active" href="clienti.php">
                  <i class="fas fa-person me-2"></i> Clienti
              </a>
          </li>
          <?php endif; ?>

          <?php if($utente_autorizzato): ?>
          <li class="nav-item">
              <a class="nav-link"
                href="https://www.google.com/maps/d/edit?mid=1IC752QuW9YEfk8nxaHfzzp9yoEqDxUA&ll=42.98766193644833%2C13.129046460766595&z=6"
                target="_blank"
                rel="noopener noreferrer">
                <i class="fas fa-globe me-2"></i> Mappa Clienti
              </a>
          </li>
          <?php endif; ?>

          <li class="nav-item">
              <a class="nav-link text-white" href="javascript:void(0);" onclick="printPage()">
                  <i class="fas fa-print me-2"></i> Stampa
              </a>
          </li>
          <li class="nav-item">
              <a class="nav-link text-white" href="GuidaSistemaReport.pdf" download>
                  <i class="fas fa-book me-2"></i> Guida
              </a>
          </li>

          <?php if($utente_autorizzato): ?>
            <li class="nav-item">
              <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true">
                <i class="fas fa-cog me-2"></i> Impostazioni
              </a>
              <p style="opacity: 0.6; font-size: 0.9em;">*Selezionare un agente per abilitare le impostazioni</p>
            </li>
          <?php endif; ?>
      </ul>

      <?php 
      // Se hai un footer o logo in basso, includilo qui
      include "logo-footer.php"; 
      ?>
  </div>
  <!-- ====================== FINE SIDEBAR ====================== -->
  
  <!-- Main Content -->
  <div class="main-content">
    <div class="container-fluid">
      <h3 class="mb-4">Clienti</h3>
      
      <!-- Card: Nuovi Clienti (conta solo chi ha acquistato e risulta nuovo) -->
      <div class="row mb-4">
        <div class="col-md-4">
          <div class="card shadow-sm">
            <div class="card-body text-center">
              <h5>Nuovi Clienti</h5>
              <div style="font-size:1.8em;font-weight:bold;"><?php echo $numNuoviClienti; ?></div>
              <p class="text-muted mb-0">(acquisto per la prima volta dal <?php echo "{$anno_selezionato}-01-01"; ?>)</p>
            </div>
          </div>
        </div>
        <!-- Card: Clienti Attivi -->
        <div class="col-md-4">
          <div class="card shadow-sm">
            <div class="card-body text-center">
              <h5>Clienti Attivi</h5>
              <div style="font-size:1.8em;font-weight:bold;"><?php echo $numAttiviRange; ?></div>
              <p class="text-muted mb-0">(acquisto nell'anno <?php echo $anno_selezionato; ?>)</p>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Card: Tabella -->
      <div class="card shadow-sm">
        <div class="card-body">
          <!-- Filtri: Ricerca, Provincia, e nella select "Solo Nuovi" ora mostra tutti quelli inseriti nell'anno -->
          <div class="row mb-3">
            <div class="col-md-3 mb-2">
              <input type="text" id="searchInput" class="form-control" placeholder="Cerca cliente..." oninput="filterTable()">
            </div>
            <div class="col-md-3 mb-2">
              <select id="provinciaSelect" class="form-select" onchange="filterTable()">
                <option value="">-- Tutte le Province --</option>
                <?php foreach($provinceSet as $pr): ?>
                  <option value="<?php echo $pr; ?>"><?php echo $pr; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3 mb-2">
              <select id="nuoviSelect" class="form-select" onchange="filterTable()">
                <option value="">Tutti i Clienti</option>
                <option value="nuovi">Solo Inseriti quest'anno</option>
              </select>
            </div>
          </div>
          
          <div class="table-responsive">
            <table class="table table-hover align-middle" id="clientiTable">
              <thead class="table-light">
                <tr>
                  <th>
                    INTS
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(0,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(0,'desc')"></i>
                    </span>
                  </th>
                  <th>Stato</th>
                  <th>
                    Agente
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(2,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(2,'desc')"></i>
                    </span>
                  </th>
                  <th>
                    Comune
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(3,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(3,'desc')"></i>
                    </span>
                  </th>
                  <th>
                    PR
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(4,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(4,'desc')"></i>
                    </span>
                  </th>
                  <th>
                    Tel/Cell
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(5,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(5,'desc')"></i>
                    </span>
                  </th>
                  <th>
                    Email
                    <span class="sort-arrows">
                      <i class="bi bi-caret-up-fill arrow-sort" onclick="sortByColumn(6,'asc')"></i>
                      <i class="bi bi-caret-down-fill arrow-sort" onclick="sortByColumn(6,'desc')"></i>
                    </span>
                  </th>
                  <?php
                  // Colonne per gli acquisiti: in ordine: anno-2, anno-1, anno
                  $startCol = 7;
                  foreach($anni_da_mostrare as $idx => $annox) {
                      echo "<th>Acquisito $annox
                            <span class='sort-arrows'>
                              <i class='bi bi-caret-up-fill arrow-sort' onclick='sortByColumn(".($startCol+$idx).",\"asc\")'></i>
                              <i class='bi bi-caret-down-fill arrow-sort' onclick='sortByColumn(".($startCol+$idx).",\"desc\")'></i>
                            </span>
                            </th>";
                  }
                  ?>
                  <th>Azioni</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($clienti as $c):
                    $statoClass = 'stato-nonattivo';
                    if($c['stato'] === 'Attivo') $statoClass = 'stato-attivo';
                    elseif($c['stato'] === 'Preventivi') $statoClass = 'stato-preventivi';
                    $isNuovoAttr = $c['isNuovo'] ? '1' : '0';
                ?>
                <!-- Aggiungo anche data-inserted per i clienti inseriti quest'anno -->
                <tr data-nuovo="<?php echo $isNuovoAttr; ?>" data-inserted="<?php echo $c['isInserted'] = ($c['isInserted'] ?? $c['isNuovo']); // Se non abbiamo isInserted, default a isNuovo ?>">
                  <td>
                    <?php echo htmlspecialchars($c['ints'], ENT_QUOTES); ?>
                    <?php if($c['isNuovo']): ?>
                      <span class="new-label">new</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="stato-pill <?php echo $statoClass; ?>">
                      <?php echo $c['stato']; ?>
                    </span>
                  </td>
                  <td><?php echo htmlspecialchars($c['agente'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($c['comune'], ENT_QUOTES); ?></td>
                  <td><?php echo htmlspecialchars($c['pr'], ENT_QUOTES); ?></td>
                  <td>
                    <?php echo htmlspecialchars($c['tell'], ENT_QUOTES); ?>
                    <?php if($c['tell'] && $c['cell']) echo '<br>'; ?>
                    <?php echo htmlspecialchars($c['cell'], ENT_QUOTES); ?>
                  </td>
                  <td><?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?></td>
                  <?php foreach($anni_da_mostrare as $idx => $annox):
                      $val = $c['acquisiti'][$annox] ?? 0;
                      $val_fmt = number_format($val, 2, ',', '.');
                      // Mostriamo la percentuale solo per l'anno-1 e l'anno corrente
                      $diffStr = '';
                      $arrow = '';
                      if($idx > 0) {
                          $prevAn = $anni_da_mostrare[$idx-1];
                          $valPrev = $c['acquisiti'][$prevAn] ?? 0;
                          if($valPrev > 0) {
                              $diffPerc = (($val - $valPrev) / $valPrev) * 100;
                          } elseif($val > 0) {
                              $diffPerc = 100;
                          } else {
                              $diffPerc = 0;
                          }
                          if($diffPerc > 0) {
                              $arrow = '<i class="bi bi-arrow-up-short arrow-up"></i>';
                              $diffStr = '+' . round($diffPerc, 1) . '%';
                          } elseif($diffPerc < 0) {
                              $arrow = '<i class="bi bi-arrow-down-short arrow-down"></i>';
                              $diffStr = round($diffPerc, 1) . '%';
                          }
                      }
                  ?>
                  <td>
                    <?php echo $val_fmt; ?>
                    <?php if($diffStr !== ''): ?>
                      <div style="font-size:0.8em; color:#666;">
                        (<?php echo $diffStr . ' ' . $arrow; ?>)
                      </div>
                    <?php endif; ?>
                  </td>
                  <?php endforeach; ?>
                  <td>
                    <form method="post" action="dettaglio_cliente.php?anno=<?php echo $anno_selezionato; ?>" class="d-inline">
                      <input type="hidden" name="richiedente" value="<?php echo htmlspecialchars($c['ints'], ENT_QUOTES); ?>">
                      <button type="submit" class="btn btn-sm btn-primary">
                        <i class="fas fa-info-circle"></i> Apri
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="mt-3" id="paginationContainer"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
let currentPage = 1;
const rowsPerPage = 50;
let initialPageLoad = true; // Scroll solo al cambio pagina

function filterTable(){
  currentPage = 1;
  updateTableDisplay();
}

function updateTableDisplay(){
  const searchVal = document.getElementById('searchInput').value.toLowerCase();
  const provVal = document.getElementById('provinciaSelect').value.toUpperCase().trim();
  const nuoviVal = document.getElementById('nuoviSelect').value; // "nuovi" per "solo inseriti quest'anno"
  
  const table = document.getElementById('clientiTable');
  const rows = table.tBodies[0].getElementsByTagName('tr');
  let filteredRows = [];
  
  for(let i = 0; i < rows.length; i++){
    let row = rows[i];
    let rowText = row.innerText.toLowerCase();
    let matchSearch = (searchVal === '') ? true : rowText.includes(searchVal);
    
    // colIndex=4 contiene la provincia (PR)
    let prText = row.cells[4].innerText.toUpperCase().trim();
    let matchProv = (provVal === '') ? true : (prText === provVal);
    
    // Filtra per "solo nuovi" usando il flag "data-inserted" (quelli inseriti quest'anno)
    let isInserted = row.dataset.inserted;
    let matchInserted = (nuoviVal === 'nuovi') ? (isInserted === '1') : true;
    
    if(matchSearch && matchProv && matchInserted){
      filteredRows.push(row);
    } else {
      row.style.display = 'none';
    }
  }
  
  let totalRows = filteredRows.length;
  let totalPages = Math.ceil(totalRows / rowsPerPage);
  if(currentPage < 1) currentPage = 1;
  if(currentPage > totalPages) currentPage = totalPages;
  
  let startIndex = (currentPage - 1) * rowsPerPage;
  let endIndex = startIndex + rowsPerPage;
  for(let i = 0; i < filteredRows.length; i++){
    if(i >= startIndex && i < endIndex){
      filteredRows[i].style.display = '';
    } else {
      filteredRows[i].style.display = 'none';
    }
  }
  
  buildPagination(totalPages);
  
  // Scroll in alto della tabella solo se si cambia pagina (non al caricamento iniziale)
  if(!initialPageLoad){
    const tableOffset = table.getBoundingClientRect().top + window.scrollY - 20;
    window.scrollTo({ top: tableOffset, behavior: 'smooth' });
  } else {
    initialPageLoad = false;
  }
}

function buildPagination(totalPages){
  const container = document.getElementById('paginationContainer');
  container.innerHTML = '';
  if(totalPages <= 1) return;
  
  let ul = document.createElement('ul');
  ul.className = 'pagination';
  
  // (prima)
  let liFirst = document.createElement('li');
  liFirst.className = 'page-item' + (currentPage > 1 ? '' : ' disabled');
  let aFirst = document.createElement('a');
  aFirst.className = 'page-link';
  aFirst.href = '#';
  aFirst.textContent = '(prima)';
  aFirst.onclick = (e) => {
    e.preventDefault();
    if(currentPage > 1){ currentPage = 1; updateTableDisplay(); }
  };
  liFirst.appendChild(aFirst);
  ul.appendChild(liFirst);
  
  // PREV «
  let liPrev = document.createElement('li');
  liPrev.className = 'page-item' + (currentPage > 1 ? '' : ' disabled');
  let aPrev = document.createElement('a');
  aPrev.className = 'page-link';
  aPrev.href = '#';
  aPrev.textContent = '«';
  aPrev.onclick = (e) => {
    e.preventDefault();
    if(currentPage > 1){ currentPage--; updateTableDisplay(); }
  };
  liPrev.appendChild(aPrev);
  ul.appendChild(liPrev);
  
  // Range di 5 pagine centrato su currentPage
  let startP = currentPage - 2;
  let endP = currentPage + 2;
  if(startP < 1){
    endP += (1 - startP);
    startP = 1;
  }
  if(endP > totalPages) endP = totalPages;
  for(let p = startP; p <= endP; p++){
    let liP = document.createElement('li');
    liP.className = 'page-item' + (p === currentPage ? ' active' : '');
    let aP = document.createElement('a');
    aP.className = 'page-link';
    aP.href = '#';
    aP.textContent = p;
    aP.onclick = (e) => {
      e.preventDefault();
      currentPage = p;
      updateTableDisplay();
    };
    liP.appendChild(aP);
    ul.appendChild(liP);
  }
  
  // NEXT »
  let liNext = document.createElement('li');
  liNext.className = 'page-item' + (currentPage < totalPages ? '' : ' disabled');
  let aNext = document.createElement('a');
  aNext.className = 'page-link';
  aNext.href = '#';
  aNext.textContent = '»';
  aNext.onclick = (e) => {
    e.preventDefault();
    if(currentPage < totalPages){ currentPage++; updateTableDisplay(); }
  };
  liNext.appendChild(aNext);
  ul.appendChild(liNext);
  
  // (ultima)
  let liLast = document.createElement('li');
  liLast.className = 'page-item' + (currentPage < totalPages ? '' : ' disabled');
  let aLast = document.createElement('a');
  aLast.className = 'page-link';
  aLast.href = '#';
  aLast.textContent = '(ultima)';
  aLast.onclick = (e) => {
    e.preventDefault();
    if(currentPage < totalPages){ currentPage = totalPages; updateTableDisplay(); }
  };
  liLast.appendChild(aLast);
  ul.appendChild(liLast);
  
  container.appendChild(ul);
}

function sortByColumn(colIndex, direction){
  const table = document.getElementById('clientiTable');
  const tbody = table.tBodies[0];
  let rowsArray = Array.from(tbody.getElementsByTagName('tr'));
  
  rowsArray.sort((rowA, rowB) => {
    let cellA = rowA.cells[colIndex].innerText.trim();
    let cellB = rowB.cells[colIndex].innerText.trim();
    
    if(colIndex >= 7 && colIndex < 10){
      let valA = parseFloat(cellA.replace(/\./g, '').replace(',','.')) || 0;
      let valB = parseFloat(cellB.replace(/\./g, '').replace(',','.')) || 0;
      return (direction==='asc') ? (valA - valB) : (valB - valA);
    } else {
      return (direction==='asc') ? cellA.localeCompare(cellB) : cellB.localeCompare(cellA);
    }
  });
  
  rowsArray.forEach(r => tbody.appendChild(r));
  filterTable();
}

document.addEventListener('DOMContentLoaded', () => {
  updateTableDisplay();
});




// ====================== JAVASCRIPT LEGATO ALLA SIDEBAR ====================== 

// Selezione agente via fetch
function selectAgente(nome, redirect = '') {
    const formData = new FormData();
    formData.append('agente', nome);
    if (redirect) {
        formData.append('redirect', redirect);
    }

    fetch('set_agente.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.redirect) {
                window.location.href = data.redirect; // Reindirizza alla pagina reportagente.php con l'agente selezionato
            } else {
                window.location.reload(); // Se non c'è un redirect, ricarica la pagina attuale
            }
        } else {
            alert("Errore nella selezione dell'agente.");
        }
    });
}

    // Filtro agenti in modale
    function filterAgenti() {
        const search = document.getElementById('searchAgente').value.toLowerCase();
        const rows = document.querySelectorAll('#tableAgenti tbody tr');
        rows.forEach(r=>{
            const agenteName = r.cells[0].textContent.toLowerCase();
            r.style.display = (agenteName.includes(search)) ? '' : 'none';
        });
    }

// Eventuale funzione per la stampa
function printPage() {
    // Se avevi un tuo printHeader puoi adattare
    const printHeader = document.getElementById('print-header');
    if (printHeader) {
        printHeader.style.display = 'block';
        window.print();
        printHeader.style.display = 'none';
    } else {
        // Se non vuoi la prima pagina personalizzata
        window.print();
    }
}



// ====================== FINE JAVASCRIPT SIDEBAR ====================== -->





</script>
</body>
</html>
