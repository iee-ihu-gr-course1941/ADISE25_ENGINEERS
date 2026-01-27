<?php

header('Content-Type: application/json; charset=utf-8');

/*
  Στέλνει JSON απάντηση στον client και τερματίζει το script
  Χρησιμοποιείται από όλα τα endpoints για:
  επιτυχημένες απαντήσεις
  σφάλματα (validation, auth, κλπ) */
function respond($arr, $code=200) {
  http_response_code($code);
  echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  exit;
}



/*
  Διαβάζει το JSON body από POST request και το επιστρέφει ως PHP array
  Χρησιμοποιείται από όλα τα API endpoints για input.
  Αν δεν υπάρχει body ή είναι άδειο, επιστρέφει []
 */
function json_in() {
  $raw = file_get_contents("php://input");
  return $raw ? json_decode($raw, true) : [];
}



//ΣΥΝΑΡΤΗΣΕΙΣ ΑΠΟ ΑΥΤΟ ΤΟ ΣΧΟΛΙΟ ΚΑΙ ΚΑΤΩ!!!!!



/*Δημιουργεί και επιστρέφει μία πλήρη τράπουλα 52 φύλλων.
  Κάθε φύλλο είναι string μορφής: - "2S", "10H", "QD", "AC"
 Δεν κάνει shuffle.
 Δεν τροποποιεί state.Δημιουργεια του deck μας*/
function build_deck(): array {
  $ranks = ['2','3','4','5','6','7','8','9','10','J','Q','K','A'];
  $suits = ['S','H','D','C']; // Spades, Hearts, Diamonds, Clubs
  $deck = [];

  foreach ($suits as $s) {
    foreach ($ranks as $r) {
      $deck[] = $r . $s; // πχ "10H", "AS"
    }
  }
  return $deck;
}
/*Μοιράζει $count φύλλα από το deck και τα αφαιρεί από αυτό.
  Το $deck περνάει by reference και τροποποιείται.
  Αν δεν υπάρχουν αρκετά φύλλα, πετάει Exception*/
function deal_cards(array &$deck, int $count): array {
  $hand = [];
  for ($i = 0; $i < $count; $i++) {
    if (empty($deck)) {
      throw new Exception("Deck empty while dealing");
    }
    $hand[] = array_pop($deck); // παίρνει 1 χαρτί και το αφαιρεί από το deck
  }
  return $hand;
}
// επιστρεφει την αξια του φυλλου χωρις το symbol
function card_rank(string $card): string {
  return substr($card, 0, -1); // "10H"->"10"
}

//επιστρεφει το συμβολο μονο 
function card_suit(string $card): string {
  // "10H" -> "H"
  return substr($card, -1);
}
/*
 * has_card
 * --------
 * Ελέγχει αν ένα συγκεκριμένο φύλλο υπάρχει μέσα σε λίστα φύλλων.
 *
 * Χρησιμοποιείται κυρίως στο τελικό scoring
 * (π.χ. για έλεγχο αν ο παίκτης έχει 2♠ ή 10♦).
 *
 * Ο έλεγχος γίνεται με strict σύγκριση (===),
 * ώστε να μην υπάρχουν λάθος matches.*/
function has_card(array $cards, string $target): bool {
  return in_array($target, $cards, true);
}
//μετραει ποσους ποντους δινει καθε φυλλο με εξαιρεση το 10spades
function count_kqj10_except_10d(array $cards): int {
  // +1 για κάθε K/Q/J/10 (όχι το 10♦)
  $count = 0;
  foreach ($cards as $c) {
    $rank = card_rank($c);
    $suit = card_suit($c);

    // 10♦ ΔΕΝ μετράει εδώ (μετράει ξεχωριστά +1)
    if ($rank === '10' && $suit === 'D') {
      continue;
    }

    if ($rank === 'K' || $rank === 'Q' || $rank === 'J' || $rank === '10') {
      $count++;
    }
  }
  return $count;
}

function compute_points(array $capturedCards, int $xeri, int $xeriJack): array {
  // Επιστρέφει breakdown + points χωρίς το  εξτρα 3 πόντους για τον παικτη με τα περισσότερα χαρτιά
  $has2S = has_card($capturedCards, '2S') ? 1 : 0;
  $has10D = has_card($capturedCards, '10D') ? 1 : 0;
  $kqj10 = count_kqj10_except_10d($capturedCards);

  $xeriPoints = ($xeri * 10) + ($xeriJack * 20);

  $points = $has2S + $has10D + $kqj10 + $xeriPoints;

  return [
    "points_no_majority" => $points,
    "cards_count" => count($capturedCards),
    "has_2S_point" => $has2S,
    "has_10D_point" => $has10D,
    "kqj10_points" => $kqj10,
    "xeri_points" => $xeriPoints,
  ];
}

//ΕΝΑΝΤΙΟΝ ΥΠΟΛΟΓΙΣΤΗ FUCTION 


function apply_move(array &$state, string $me, string $card, int &$scoreP1, int &$scoreP2, int $currentTurn, int &$newTurn, bool &$gameFinished, ?array &$final): array {
  // returns flags for response
  $state['captured'] = $state['captured'] ?? ["p1"=>[], "p2"=>[]];
  $state['xeri'] = $state['xeri'] ?? ["p1"=>0, "p2"=>0];
  $state['xeri_jack'] = $state['xeri_jack'] ?? ["p1"=>0, "p2"=>0];
  $state['last_capturer'] = $state['last_capturer'] ?? null;
  $state['hands'] = $state['hands'] ?? ["p1"=>[], "p2"=>[]];
  $state['table'] = $state['table'] ?? [];
  $state['deck'] = $state['deck'] ?? [];

  $hand = $state['hands'][$me] ?? [];
  $table = $state['table'] ?? [];

  // 1) remove card from hand
  $idx = array_search($card, $hand, true);
  if ($idx === false) {
    throw new Exception("card not in your hand");
  }
  array_splice($hand, $idx, 1);

  // 2) capture logic (top only)
  $topCard = (count($table) > 0) ? $table[count($table)-1] : null;
  $playedRank = card_rank($card);

  $canCapture = false;
  if ($topCard !== null) {
    $topRank = card_rank($topCard);
    if ($playedRank === 'J' || $playedRank === $topRank) $canCapture = true;
  }

  $captured = false;
  $xeri = false;
  $xeriJack = false;

  if (!$canCapture) {
    // drop
    $table[] = $card;
    $state['table'] = $table;
    $state['hands'][$me] = $hand;
  } else {
    // capture whole pile + played card
    $captured = true;

    if (count($table) === 1) {
      $xeri = true;
      if ($playedRank === 'J') $xeriJack = true;
    }

    $pile = $table;
    $pile[] = $card;

    $state['captured'][$me] = array_merge($state['captured'][$me] ?? [], $pile);
    $state['last_capturer'] = $me;
    $state['table'] = [];
    $state['hands'][$me] = $hand;

    if ($xeriJack) $state['xeri_jack'][$me] = ($state['xeri_jack'][$me] ?? 0) + 1;
    else if ($xeri) $state['xeri'][$me] = ($state['xeri'][$me] ?? 0) + 1;

    $bonus = 0;
    if ($xeriJack) $bonus = 20;
    else if ($xeri) $bonus = 10;

    if ($bonus > 0) {
      if ($me === 'p1') $scoreP1 += $bonus;
      else $scoreP2 += $bonus;
    }
  }

  // 3) switch turn
  $newTurn = ($currentTurn === 1) ? 2 : 1;

  // 4) refill if both empty and deck has cards
  $deckNow = $state['deck'] ?? [];
  $h1 = $state['hands']['p1'] ?? [];
  $h2 = $state['hands']['p2'] ?? [];

  if (count($h1) === 0 && count($h2) === 0 && count($deckNow) > 0) {
    $n1 = min(6, count($deckNow));
    $state['hands']['p1'] = deal_cards($deckNow, $n1);

    $n2 = min(6, count($deckNow));
    $state['hands']['p2'] = deal_cards($deckNow, $n2);

    $state['deck'] = $deckNow;
    $h1 = $state['hands']['p1'];
    $h2 = $state['hands']['p2'];
  }

  // 5) end game check + final scoring
  $deckNow = $state['deck'] ?? [];
  $gameFinished = (count($deckNow) === 0 && count($h1) === 0 && count($h2) === 0);
  $final = null;

  if ($gameFinished) {
    // remaining table///last capturer
    if (!empty($state['table']) && ($state['last_capturer'] === 'p1' || $state['last_capturer'] === 'p2')) {
      $lc = $state['last_capturer'];
      $state['captured'][$lc] = array_merge($state['captured'][$lc] ?? [], $state['table']);
      $state['table'] = [];
    }

    $p1cards = $state['captured']['p1'] ?? [];
    $p2cards = $state['captured']['p2'] ?? [];

    $x1  = (int)($state['xeri']['p1'] ?? 0);
    $x2  = (int)($state['xeri']['p2'] ?? 0);
    $xj1 = (int)($state['xeri_jack']['p1'] ?? 0);
    $xj2 = (int)($state['xeri_jack']['p2'] ?? 0);

    $b1 = compute_points($p1cards, $x1, $xj1);
    $b2 = compute_points($p2cards, $x2, $xj2);

    $majorityP1 = 0; $majorityP2 = 0;
    if ($b1['cards_count'] > $b2['cards_count']) $majorityP1 = 3;
    else if ($b2['cards_count'] > $b1['cards_count']) $majorityP2 = 3;

    // overwrite final
    $scoreP1 = $b1['points_no_majority'] + $majorityP1;
    $scoreP2 = $b2['points_no_majority'] + $majorityP2;

    $final = [
      "p1" => array_merge($b1, ["majority_points"=>$majorityP1, "total"=>$scoreP1]),
      "p2" => array_merge($b2, ["majority_points"=>$majorityP2, "total"=>$scoreP2]),
      "winner" => ($scoreP1 > $scoreP2 ? "p1" : ($scoreP2 > $scoreP1 ? "p2" : "draw")),
    ];
  }

  return [
    "captured" => $captured,
    "xeri" => $xeri,
    "xeriJack" => $xeriJack,
  ];
}

function cpu_throw_low(array $hand): string {
  foreach ($hand as $c) {
    if ($c === '10D' || $c === '2S') continue;
    $r = card_rank($c);
    if (in_array($r, ['K','Q','J','10'], true)) continue;
    return $c;
  }
  return $hand[0];
}

function cpu_choose_card(array $cpuHand, array $table): string {
  if (empty($cpuHand)) throw new Exception("CPU has no cards");

  $top = count($table) ? $table[count($table)-1] : null;
  $tableCount = count($table);

  if ($top === null) return cpu_throw_low($cpuHand);

  $topRank = card_rank($top);
  $captures = [];
  foreach ($cpuHand as $c) {
    $r = card_rank($c);
    if ($r === 'J' || $r === $topRank) $captures[] = $c;
  }

  if (!empty($captures)) {
    // if xeri chance, prefer J
    if ($tableCount === 1) {
      foreach ($captures as $c) if (card_rank($c) === 'J') return $c;
      return $captures[0];
    }
    // otherwise save J if possible
    foreach ($captures as $c) if (card_rank($c) !== 'J') return $c;
    return $captures[0];
  }

  return cpu_throw_low($cpuHand);
}

function cpu_take_turn_if_needed(array &$state, int &$turn, int &$scoreP1, int &$scoreP2, bool &$gameFinished, ?array &$final, array &$cpuDebugMoves): void {
  // CPU is always p2, plays when turn==2
  if ($turn !== 2) return;
  if ($gameFinished) return;

  $cpuHand = $state['hands']['p2'] ?? [];
  $table = $state['table'] ?? [];

  if (empty($cpuHand)) {
    // if CPU has no cards, do nothing; refill/end will happen on next move if applicable
    return;
  }

  $card = cpu_choose_card($cpuHand, $table);

  $newTurn = 1;
  $flags = apply_move($state, 'p2', $card, $scoreP1, $scoreP2, 2, $newTurn, $gameFinished, $final);
  $turn = $newTurn;

  $cpuDebugMoves[] = [
    "played" => $card,
    "captured" => $flags["captured"],
    "xeri" => $flags["xeri"],
    "xeriJack" => $flags["xeriJack"],
  ];
}


//ΣΥΝΑΡΤΗΣΕΙΣ ΝΑ ΜΠΑΙΝΟΥΝ ΜΟΝΟ ΠΑΝΩ ΑΠΟ ΑΥΤΟ ΤΟ ΣΧΟΛΙΟ!!!!!!!!



/**
 * Σύνδεση στη βάση MySQL
 */
try {
  $pdo = new PDO(
    "mysql:host=127.0.0.1;dbname=xeri;charset=utf8mb4",
    "root",
    "",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
  );
} catch (Exception $e) {
  respond(["ok"=>false, "error"=>"DB connection failed", "detail"=>$e->getMessage()], 500);
}

$action = $_GET['action'] ?? '';



/**
 * GET ping → έλεγχος API + DB
 */
if ($action === 'ping') {
  respond(["ok"=>true, "msg"=>"API+DB OK"]);
}



/**
 * POST auth/login χωρίς password
 *  Δεν υπάρχει password (απλό login με username)
 *  Κάθε login δημιουργεί ΝΕΟ token
 *  Το token χρησιμοποιείται για authentication σε όλα τα υπόλοιπα endpoints
 */
if ($action === 'auth') {
  $in = json_in();
  $username = trim($in['username'] ?? '');
//Αν το username είναι κενό επιστρέφει error 400
  if ($username === '') {
    respond(["ok"=>false, "error"=>"username required"], 400);
  }
//δημιουργεια token 
  $token = bin2hex(random_bytes(32));

  $st = $pdo->prepare("SELECT id, username FROM users WHERE username=?");
  $st->execute([$username]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
//Ελέγχει αν ο χρήστης υπάρχει ήδη στη βάση αν ναι ενημερωνει τοκεν αν οχι κανει νεο user και βαζει το τοκεν
  if ($u) {
    $pdo->prepare("UPDATE users SET token=? WHERE id=?")->execute([$token, $u['id']]);
    respond(["ok"=>true, "token"=>$token, "user"=>$u]);
  } else {
    $pdo->prepare("INSERT INTO users(username, token) VALUES(?,?)")->execute([$username, $token]);
    $id = (int)$pdo->lastInsertId();
    respond(["ok"=>true, "token"=>$token, "user"=>["id"=>$id, "username"=>$username]]);
  }
}




/**
 * POST create_game → δημιουργία νέου παιχνιδιού
 */
if ($action === 'create_game') {
  $in = json_in();
  $token = $in['token'] ?? '';

  if ($token === '') {
    respond(["ok"=>false, "error"=>"token required"], 400);
  }

  // Βρες τον χρήστη από το token
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }

  // Αρχική κατάσταση παιχνιδιού
  $state = [
    "deck" => [],
    "table" => [],
    "hands" => [
      "p1" => [],
      "p2" => []
    ],
    "captured" => ["p1"=>[],"p2"=>[]],
    "xeri" => ["p1"=>0, "p2"=>0],
    "xeri_jack" => ["p1"=>0, "p2"=>0],
    "last_capturer" => null
  ];

  // Δημιουργία παιχνιδιού στην βαση
  $pdo->prepare("
    INSERT INTO games (player1_id, `STATUS`, state)
    VALUES (?, 'waiting', ?)
  ")->execute([
    $user['id'],
    json_encode($state)
  ]);

  $game_id = (int)$pdo->lastInsertId();

  respond([
    "ok" => true,
    "game_id" => $game_id,
    "status" => "waiting"
  ]);
}

//ENANTION YPOLOGISTI 
if ($action === 'create_game_cpu') {
  $in = json_in();
  $token = $in['token'] ?? '';
  if ($token === '') respond(["ok"=>false,"error"=>"token required"], 400);

  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) respond(["ok"=>false,"error"=>"invalid token"], 401);

  $state = [
    "deck" => [],
    "table" => [],
    "hands" => ["p1"=>[], "p2"=>[]],
    "captured" => ["p1"=>[],"p2"=>[]],
    "xeri" => ["p1"=>0, "p2"=>0],
    "xeri_jack" => ["p1"=>0, "p2"=>0],
    "last_capturer" => null
  ];

  $deck = build_deck();
  shuffle($deck);

  $state['hands']['p1'] = deal_cards($deck, 6);
  $state['hands']['p2'] = deal_cards($deck, 6); // CPU
  $state['table'] = deal_cards($deck, 4);
  $state['deck'] = $deck;

  $pdo->prepare("
    INSERT INTO games (player1_id, player2_id, `STATUS`, turn, score_p1, score_p2, state, vs_cpu, cpu_difficulty)
    VALUES (?, NULL, 'playing', 1, 0, 0, ?, 1, 'normal')
  ")->execute([
    (int)$user['id'],
    json_encode($state, JSON_UNESCAPED_UNICODE)
  ]);

  $game_id = (int)$pdo->lastInsertId();
  respond(["ok"=>true, "game_id"=>$game_id, "status"=>"playing", "vs_cpu"=>true]);
}



/**
 * POST join_game → ο 2ος παίκτης μπαίνει σε παιχνίδι που περιμένει
 * input: { token, game_id }
 */
if ($action === 'join_game') {
  $in = json_in();
  $token = $in['token'] ?? '';
  $game_id = (int)($in['game_id'] ?? 0);

  if ($token === '' || $game_id <= 0) {
    respond(["ok"=>false, "error"=>"token and game_id required"], 400);
  }




  // Βρες user από token
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }





  // Βρες το game
  $st = $pdo->prepare("SELECT * FROM games WHERE id=?");
  $st->execute([$game_id]);
  $g = $st->fetch(PDO::FETCH_ASSOC);

  if (!$g) {
    respond(["ok"=>false, "error"=>"game not found"], 404);
  }

 $status = $g['status'] ?? $g['STATUS'] ?? null;
if ($status !== 'waiting') {
  respond(["ok"=>false, "error"=>"game not in waiting status"], 409);
}

  if ((int)$g['player1_id'] === (int)$user['id']) {
    respond(["ok"=>false, "error"=>"player1 cannot join as player2"], 409);
  }

  if (!empty($g['player2_id'])) {
    respond(["ok"=>false, "error"=>"game already has player2"], 409);
  }

  // Κλείδωμα: κάνε update μόνο αν ακόμα waiting και player2 είναι NULL
  $upd = $pdo->prepare("
    UPDATE games
    SET player2_id = ?, `STATUS` = 'playing', turn = 1
    WHERE id = ? AND `STATUS` = 'waiting' AND player2_id IS NULL
  ");
  $upd->execute([$user['id'], $game_id]);

  if ($upd->rowCount() === 0) {
    respond(["ok"=>false, "error"=>"someone already joined"], 409);
  }



// --- START: φτιάχνουμε τράπουλα + shuffle + μοίρασμα και ενημερώνουμε state
$DEAL = 6;

// Πάρε το υπάρχον state (είναι JSON κείμενο στη βάση)
$state = json_decode($g['state'] ?? '', true);
if (!is_array($state)) {
  $state = ["deck"=>[],"table"=>[],"hands"=>["p1"=>[],"p2"=>[]]];
}

// Φτιάξε τράπουλα 52 και ανακάτεψε
$deck = build_deck();
shuffle($deck);

$handP1 = deal_cards($deck, 6);    // 6 στο χερι του παικτη 1 και 6 στου παικτη 2
$handP2 = deal_cards($deck, 6);
$table4 = deal_cards($deck, 4);   //  4 στο τραπέζι 

$state['hands']['p1'] = $handP1;
$state['hands']['p2'] = $handP2;
$state['table'] = $table4;
$state['deck'] = $deck;
$state['captured'] = $state['captured'] ?? ["p1"=>[], "p2"=>[]];
$state['xeri'] = $state['xeri'] ?? ["p1"=>0, "p2"=>0];
$state['xeri_jack'] = $state['xeri_jack'] ?? ["p1"=>0, "p2"=>0];
$state['last_capturer'] = $state['last_capturer'] ?? null;




// Γράψ' το πίσω στη βάση (JSON κείμενο)
$pdo->prepare("UPDATE games SET state=? WHERE id=?")
    ->execute([json_encode($state, JSON_UNESCAPED_UNICODE), $game_id]);

  respond([
    "ok" => true,
    "msg" => "joined game",
    "game_id" => $game_id,
    "status" => "playing",
    "player2" => ["id" => (int)$user['id'], "username" => $user['username']]
  ]);
}




/**
 * POST get_game_state
 * input: { token, game_id }
 *
 * Επιστρέφει ΜΟΝΟ:
 * - status/turn (από τον πίνακα games)
 * - table (από state)
 * - το δικό μου hand (από state)
 *
 * ΔΕΝ επιστρέφει:
 * - deck
 * - hand αντιπάλου
 */
if ($action === 'get_game_state') {

  // =========================
  // 1) Πάρε input
  // =========================
  $in = json_in();
  $token = $in['token'] ?? '';
  $game_id = (int)($in['game_id'] ?? 0);

  if ($token === '' || $game_id <= 0) {
    respond(["ok"=>false, "error"=>"token and game_id required"], 400);
  }

  // =========================
  // 2) Ταυτοποίηση χρήστη (token -> user)
  // =========================
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }

  // =========================
  // 3) Φόρτωσε το παιχνίδι
  // =========================
  $st = $pdo->prepare("SELECT * FROM games WHERE id=?");
  $st->execute([$game_id]);
  $g = $st->fetch(PDO::FETCH_ASSOC);

  if (!$g) {
    respond(["ok"=>false, "error"=>"game not found"], 404);
  }

  // Παίρνουμε status από τη στήλη STATUS (κεφαλαία)
  $status = $g['STATUS'] ?? $g['status'] ?? null;

  // =========================
  // 4) Authorization:
  //    Ο χρήστης ΠΡΕΠΕΙ να είναι παίκτης σε αυτό το game.
  //    Το token από μόνο του δεν αρκεί!
  // =========================
  $p1 = (int)$g['player1_id'];
  $p2 = (int)($g['player2_id'] ?? 0);
  $uid = (int)$user['id'];

  if ($uid !== $p1 && $uid !== $p2) {
    respond(["ok"=>false, "error"=>"not a player of this game"], 403);
  }

  // =========================
  // 5) Διάβασε state (JSON -> PHP array)
  // =========================
  $state = json_decode($g['state'] ?? '', true);
  if (!is_array($state)) {
    // fallback για ασφάλεια αν κάτι πάει στραβά
    $state = ["deck"=>[],"table"=>[],"hands"=>["p1"=>[],"p2"=>[]]];
  }

  // =========================
  // 6) Βρες ποιος είμαι: p1 ή p2
  // =========================
  $me = ($uid === $p1) ? 'p1' : 'p2';

  // =========================
  // 7) Φιλτράρουμε πληροφορία:
  //    Επιστρέφουμε ΜΟΝΟ ό,τι επιτρέπεται να δει ο παίκτης.
  // =========================
  $my_hand = $state['hands'][$me] ?? [];
  $table = $state['table'] ?? [];
$vs_cpu = (int)($g['vs_cpu'] ?? 0) === 1;
  // =========================
  // 8) Response
  // =========================
  respond([
    "ok" => true,
    "game_id" => $game_id,
    "status" => $status,
    "turn" => (int)($g['turn'] ?? 1),
    "me" => $me,
    "hand" => $my_hand,
    "table" => $table,
    "score_p1" => (int)($g['score_p1'] ?? 0),
    "score_p2" => (int)($g['score_p2'] ?? 0),
    "vs_cpu" => $vs_cpu
  ]);
}




if ($action === 'play_card') {

  // =========================
  // 1) Input
  // =========================
  $in = json_in();
  $token = $in['token'] ?? '';
  $game_id = (int)($in['game_id'] ?? 0);
  $card = trim($in['card'] ?? '');

  if ($token === '' || $game_id <= 0 || $card === '') {
    respond(["ok"=>false, "error"=>"token, game_id and card required"], 400);
  }

  // =========================
  // 2) Βρες χρήστη από token
  // =========================
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);
  if (!$user) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }

  // =========================
  // 3) Φόρτωσε παιχνίδι
  // =========================
  $st = $pdo->prepare("SELECT * FROM games WHERE id=?");
  $st->execute([$game_id]);
  $g = $st->fetch(PDO::FETCH_ASSOC);
  if (!$g) {
    respond(["ok"=>false, "error"=>"game not found"], 404);
  }

  $status = $g['STATUS'] ?? $g['status'] ?? null;
  if ($status !== 'playing') {
    respond(["ok"=>false, "error"=>"game not playing"], 409);
  }

  // =========================
  // 4) Authorization: είναι παίκτης;
  // =========================
  $p1 = (int)$g['player1_id'];
  $p2 = (int)($g['player2_id'] ?? 0);
  $uid = (int)$user['id'];

  if ($uid !== $p1 && $uid !== $p2) {
    respond(["ok"=>false, "error"=>"not a player of this game"], 403);
  }

  // Ποιος είμαι;
  $me = ($uid === $p1) ? 'p1' : 'p2';

  // Το turn στη DB είναι 1 για p1, 2 για p2
  $myTurnNumber = ($me === 'p1') ? 1 : 2;

  // =========================
  // 5) Έλεγχος σειράς
  // =========================
  $turn = (int)($g['turn'] ?? 1);
  if ($turn !== $myTurnNumber) {
    respond(["ok"=>false, "error"=>"not your turn", "turn"=>$turn, "me"=>$me], 409);
  }


  // =========================
  // 6) Φόρτωσε state (JSON -> array)
  // =========================
  $state = json_decode($g['state'] ?? '', true);
  if (!is_array($state)) {
    $state = ["deck"=>[],"table"=>[],"hands"=>["p1"=>[],"p2"=>[]]];
  }

  $scoreP1 = (int)($g['score_p1'] ?? 0);
  $scoreP2 = (int)($g['score_p2'] ?? 0);

// Εξασφάλιση βασικών keys (αν λείπουν)
if (!isset($state['hands'])) $state['hands'] = ["p1"=>[], "p2"=>[]];
if (!isset($state['table'])) $state['table'] = [];
if (!isset($state['deck']))  $state['deck']  = [];

$gameFinished = false;
$final = null;

try {
  $newTurn = $turn; // θα αλλάξει μέσα στην apply_move
  $flags = apply_move(
    $state,      // by-ref (θα τροποποιηθεί)
    $me,         // 'p1' ή 'p2'
    $card,       // πχ "7D"
    $scoreP1,    // by-ref
    $scoreP2,    // by-ref
    $turn,       // current turn number (1/2)
    $newTurn,    // by-ref
    $gameFinished, // by-ref
    $final       // by-ref
  );
} catch (Exception $e) {
  respond(["ok"=>false, "error"=>$e->getMessage()], 409);
}

$turn = $newTurn;

// CPU auto-move (αν το game είναι vs_cpu)
$vs_cpu = (int)($g['vs_cpu'] ?? 0) === 1;
$cpuMoves = [];

if ($vs_cpu && !$gameFinished) {
  cpu_take_turn_if_needed($state, $turn, $scoreP1, $scoreP2, $gameFinished, $final, $cpuMoves);
}

// Αποθήκευση στη βάση
if ($gameFinished) {
  $pdo->prepare("
    UPDATE games
    SET state=?, `STATUS`='finished', score_p1=?, score_p2=?, updated_at=NOW()
    WHERE id=?
  ")->execute([
    json_encode($state, JSON_UNESCAPED_UNICODE),
    $scoreP1, $scoreP2,
    $game_id
  ]);
} else {
  $pdo->prepare("
    UPDATE games
    SET state=?, turn=?, score_p1=?, score_p2=?, updated_at=NOW()
    WHERE id=?
  ")->execute([
    json_encode($state, JSON_UNESCAPED_UNICODE),
    $turn,
    $scoreP1, $scoreP2,
    $game_id
  ]);
}

// Response
respond([
  "ok" => true,
  "msg" => "card played",
  "game_id" => $game_id,
  "me" => $me,
  "played" => $card,
  "captured" => $flags["captured"],
  "xeri" => $flags["xeri"],
  "xeriJack" => $flags["xeriJack"],
  "turn" => $turn,
  "score_p1" => $scoreP1,
  "score_p2" => $scoreP2,
  "hand" => $state['hands'][$me],
  "table" => $state['table'],
  "table_top" => (count($state['table']) > 0 ? $state['table'][count($state['table']) - 1] : null),
  "deck_count" => count($state['deck'] ?? []),
  "finished" => $gameFinished,
  "final" => $final,
  "hand_count_p1" => count($state['hands']['p1'] ?? []),
  "hand_count_p2" => count($state['hands']['p2'] ?? []),
  "vs_cpu" => $vs_cpu,
  "cpu_moves" => $cpuMoves
]);
}



/**
 * POST list_waiting_games
 * input: { token }
 * Επιστρέφει παιχνίδια που περιμένουν 2ο παίκτη (STATUS=waiting και player2_id IS NULL)
 */
if ($action === 'list_waiting_games') {

  // 1) Input
  $in = json_in();
  $token = $in['token'] ?? '';

  if ($token === '') {
    respond(["ok"=>false, "error"=>"token required"], 400);
  }

  // 2) Έλεγχος token -> user (ώστε μόνο logged-in χρήστες να βλέπουν λίστα)
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $meUser = $st->fetch(PDO::FETCH_ASSOC);

  if (!$meUser) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }

  // 3) SQL: βρες games που περιμένουν + φέρε username του player1
  $st = $pdo->prepare("
    SELECT 
      g.id AS game_id,
      g.player1_id,
      u.username AS player1_username,
      g.created_at
    FROM games g
    JOIN users u ON u.id = g.player1_id
    WHERE g.`STATUS`='waiting' AND g.player2_id IS NULL
    ORDER BY g.created_at DESC
    LIMIT 20
  ");
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // 4) Κάνε map σε ωραίο JSON (σωστοί τύποι: int για ids)
  $games = [];
  foreach ($rows as $r) {
    $games[] = [
      "game_id" => (int)$r['game_id'],
      "player1" => [
        "id" => (int)$r['player1_id'],
        "username" => $r['player1_username']
      ],
      "created_at" => $r['created_at']
    ];
  }

  // 5) Response
  respond([
    "ok" => true,
    "me" => ["id" => (int)$meUser['id'], "username" => $meUser['username']],
    "games" => $games
  ]);
}

/**
 * POST cancel_game
 * input: { token, game_id }
 * Επιτρέπει στον player1 να ακυρώσει game σε STATUS=waiting
 */
if ($action === 'cancel_game') {

  //  Input
  $in = json_in();
  $token = $in['token'] ?? '';
  $game_id = (int)($in['game_id'] ?? 0);

  if ($token === '' || $game_id <= 0) {
    respond(["ok"=>false, "error"=>"token and game_id required"], 400);
  }

  //  Token βρες user
  $st = $pdo->prepare("SELECT id, username FROM users WHERE token=?");
  $st->execute([$token]);
  $user = $st->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    respond(["ok"=>false, "error"=>"invalid token"], 401);
  }

  // Φόρτωσε το game
  $st = $pdo->prepare("SELECT * FROM games WHERE id=?");
  $st->execute([$game_id]);
  $g = $st->fetch(PDO::FETCH_ASSOC);

  if (!$g) {
    respond(["ok"=>false, "error"=>"game not found"], 404);
  }

  $status = $g['STATUS'] ?? $g['status'] ?? null;

  // Μόνο waiting games ακυρώνονται
  if ($status !== 'waiting') {
    respond(["ok"=>false, "error"=>"game not in waiting status"], 409);
  }

  //Μόνο ο player1 μπορεί να το ακυρώσει
  if ((int)$g['player1_id'] !== (int)$user['id']) {
    respond(["ok"=>false, "error"=>"only player1 can cancel this game"], 403);
  }

  //Διαγραφή game
  $pdo->prepare("DELETE FROM games WHERE id=?")->execute([$game_id]);


  respond([
    "ok" => true,
    "msg" => "game cancelled",
    "game_id" => $game_id
  ]);
}



respond(["ok"=>false, "error"=>"unknown action"], 404);



