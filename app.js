// 1) Base URL του API
const API = "http://localhost/xeri/api.php";

// 2) Μικρός helper: βρίσκω στοιχεία HTML εύκολα
const $ = (id) => document.getElementById(id);


// 3) Κατάσταση εφαρμογής (frontend state)
const state = {
  token: localStorage.getItem("token") || null,
  user: JSON.parse(localStorage.getItem("user") || "null"),
  screen: "login",   // login | lobby | game
  gameId: null,
  gameState: null    // το τελευταίο get_game_state
};


// τα 3 slide μας
function showScreen(name) {
  state.screen = name;

  $("screen-login").style.display = (name === "login") ? "block" : "none";
  $("screen-lobby").style.display = (name === "lobby") ? "block" : "none";
  $("screen-game").style.display  = (name === "game")  ? "block" : "none";
}

function cardImage(card) {
  const suit = card.slice(-1);    // S H D C
  const rank = card.slice(0, -1); // 2..10 J Q K A

  const suitMap = {
    S: "spades",
    H: "hearts",
    D: "diamonds",
    C: "clubs"
  };

  const rankMap = {
    A: "ace",
    K: "king",
    Q: "queen",
    J: "jack"
  };

  const rankName = rankMap[rank] || rank;
  const suitName = suitMap[suit];

  return `cards/${rankName}_of_${suitName}.png`;
}


//Helper για POST JSON στο API
async function apiPost(action, data) {
  const res = await fetch(`${API}?action=${action}`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data)
  });

  const text = await res.text(); // ⬅️ παίρνουμε πρώτα plain text
  let json = null;

  try {
    json = JSON.parse(text);
  } catch (e) {
    console.error("Server returned non-JSON:", text);
    throw new Error("Ο server δεν επέστρεψε JSON (δες Console)");
  }

  if (!res.ok || json?.ok === false) {
    throw new Error(json?.error || `HTTP ${res.status}`);
  }

  return json;
}

//Login flow (auth)
async function doLogin() {
  const username = $("login-username").value.trim();
  if (!username) {
    alert("Γράψε username");
    return;
  }

  try {
    const d = await apiPost("auth", { username });

    // αποθήκευση σε state + localStorage (για να μη χάνεται στο refresh)
    state.token = d.token;
    state.user = d.user;

    localStorage.setItem("token", d.token);
    localStorage.setItem("user", JSON.stringify(d.user));

    showScreen("lobby");
    await refreshLobby();

  } catch (err) {
    alert("Login error: " + err.message);
  }
}




//Lobby: φόρτωση waiting games
async function refreshLobby() {
  if (!state.token) return;

  const ul = $("waiting-games");
  ul.innerHTML = "<li>Φόρτωση...</li>";

  try {
    const d = await apiPost("list_waiting_games", { token: state.token });

    ul.innerHTML = "";

    if (d.games.length === 0) {
      ul.innerHTML = "<li>Δεν υπάρχουν διαθέσιμα παιχνίδια</li>";
      return;
    }

    for (const g of d.games) {
  const li = document.createElement("li");

  const isMine = state.user && (g.player1.id === state.user.id);
  li.textContent = `Game #${g.game_id} (player1: ${g.player1.username}) `;

  if (isMine) {
    // Αν είναι δικό μου waiting game μπορώ να το ακυρώσω
    const btnCancel = document.createElement("button");
    btnCancel.textContent = "Cancel";
    btnCancel.onclick = async () => {
      if (!confirm("Ακύρωση παιχνιδιού;")) return;

      try {
        await apiPost("cancel_game", { token: state.token, game_id: g.game_id });
        await refreshLobby();
      } catch (err) {
        alert("Cancel error: " + err.message);
      }
    };
    li.appendChild(btnCancel);

  } else {
    // Αλλιώς μπορώ να κάνω join
    const btnJoin = document.createElement("button");
    btnJoin.textContent = "Join";
    btnJoin.onclick = () => joinGame(g.game_id);
    li.appendChild(btnJoin);
  }

  ul.appendChild(li);
}


  } catch (err) {
    ul.innerHTML = `<li>Σφάλμα: ${err.message}</li>`;
  }
}


async function createGame() {
  try {
    const d = await apiPost("create_game", { token: state.token });

    // ΡΑΤΑΜΕ το game_id που έφτιαξε ο player1
    state.gameId = d.game_id;

    //ΠΗΓΑΙΝΟΥΜΕ στη game οθόνη (σαν “waiting room”)
    showScreen("game");

    //ξεκινάμε polling για να δούμε πότε θα μπει ο player2
    await refreshGame();
    startPolling();

  } catch (err) {
    alert("Create game error: " + err.message);
  }
}





//player2 join game
async function joinGame(gameId) {
  try {
    const d = await apiPost("join_game", { token: state.token, game_id: gameId });
    state.gameId = gameId;
    showScreen("game");
    await refreshGame();
    startPolling();
  } catch (err) {
    alert("Join error: " + err.message);
  }
}



//Game screen: refresh + render
async function refreshGame() {
  if (!state.gameId) return;

  const d = await apiPost("get_game_state", {
    token: state.token,
    game_id: state.gameId
  });

  state.gameState = d;
    $("status-info").textContent = d.status;
    const s1 = d.score_p1 ?? 0;
const s2 = d.score_p2 ?? 0;
$("score-info").textContent = `p1: ${s1} | p2: ${s2}`;


  if (d.status === "waiting") {
  $("turn-info").textContent = "Περιμένουμε να μπει ο 2ος παίκτης...";

  const tableDiv = $("table-cards");
  if (tableDiv) tableDiv.innerHTML = `<div class="table-empty">(το παιχνίδι δεν ξεκίνησε)</div>`;

  const handDiv = $("my-hand");
  if (handDiv) handDiv.innerHTML = "";

  return;
}
// Αν τελείωσε το παιχνίδι, σταματάμε το polling και δείχνουμε αποτέλεσμα
if (d.status === "finished") {
  stopPolling();

  const myScore = (d.me === "p1") ? d.score_p1 : d.score_p2;
  const oppScore = (d.me === "p1") ? d.score_p2 : d.score_p1;

  $("turn-info").textContent = `ΤΕΛΟΣ! Σκορ: εσύ ${myScore} - αντίπαλος ${oppScore}`;
  const tableDiv = $("table-cards");
if (tableDiv) tableDiv.innerHTML = `<div class="table-empty">(τέλος παιχνιδιού)</div>`;

const handDiv = $("my-hand");
if (handDiv) handDiv.innerHTML = "";

  $("my-hand").innerHTML = "";
  

    setTimeout(() => {
    state.gameId = null;
    state.gameState = null;
    showScreen("lobby");
    refreshLobby();
    }, 2500);

  return;
}


  // Render: σειρά
  $("turn-info").textContent = `turn=${d.turn} (player ${d.me})`;

  // Render: top τραπεζιού
const tableDiv = $("table-cards");
tableDiv.innerHTML = "";

if (!d.table.length) {
  tableDiv.innerHTML = `<div class="table-empty">(άδειο)</div>`;
} else {
  d.table.forEach((c, i) => {
    const img = document.createElement("img");
    img.src = cardImage(c);
    img.alt = c;
    img.className = "table-card";

    // μικρή στοίβα (το τελευταίο να φαίνεται πιο πάνω)
    img.style.transform = `translateX(${i * 14}px) translateY(${-i * 1}px)`;
    img.style.zIndex = 10 + i;

    tableDiv.appendChild(img);
  });
}


  // Render: χέρι
  const handDiv = $("my-hand");
handDiv.innerHTML = "";

for (const c of d.hand) {
  const btn = document.createElement("button");
  btn.className = "card-btn";
  btn.onclick = () => playCard(c);

  const img = document.createElement("img");
  img.src = cardImage(c);
  img.alt = c;

  btn.appendChild(img);
  handDiv.appendChild(btn);
}

}


//patima kartas
async function playCard(card) {
  try {
    const d = await apiPost("play_card", {
      token: state.token,
      game_id: state.gameId,
      card
    });
    //vs ypologisti
    if (d.vs_cpu && d.cpu_moves && d.cpu_moves.length) {
  const m = d.cpu_moves[0];
  let msg = `ο υπολογιστης έπαιξε: ${m.played}`;
  if (m.captured) msg += m.xeriJack ? " (Ξερή με Βαλέ!)" : (m.xeri ? " (Ξερή!)" : " (μάζεψε)");
  $("game-msg").textContent = msg;
}


    // Αν τελείωσε, δείξε τελικό
    if (d.finished) {
      alert("Τέλος παιχνιδιού! Νικητής: " + d.final.winner);
      console.log("FINAL:", d.final);
    }

    // Μετά από κίνηση, φρεσκάρουμε την εικόνα
    await refreshGame();

  } catch (err) {
    alert("Play error: " + err.message);
  }
}


//gia na blepei o player2 apo to browser toy tis allages δηλαδη κανει refresh
let pollTimer = null;

function startPolling() {
  stopPolling();
  pollTimer = setInterval(() => {
    // δεν περιμένουμε με await
    refreshGame().catch((e) => console.error("Polling refresh error:", e));
  }, 1500);
}

function stopPolling() {
  if (pollTimer) clearInterval(pollTimer);
  pollTimer = null;
}

function logout() {
  stopPolling();                // αν τρέχει polling, το κόβουμε
  state.token = null;
  state.user = null;
  state.gameId = null;
  state.gameState = null;

  localStorage.removeItem("token");
  localStorage.removeItem("user");

  showScreen("login");
}


//Συνδέουμε κουμπιά όταν φορτώσει η σελίδα
window.addEventListener("load", async () => {
  $("btn-login").onclick = doLogin;
  $("btn-create-game").onclick = createGame;
$("btn-refresh-lobby").onclick = refreshLobby;
$("btn-logout").onclick = logout;
$("btn-logout-game").onclick = logout;
//vs ypologisti
$("btn-create-cpu").onclick = async () => {
  try {
    const d = await apiPost("create_game_cpu", { token: state.token });
    state.gameId = d.game_id;
    showScreen("game");
    await refreshGame();
    startPolling();
  } catch (err) {
    alert("Create CPU game error: " + err.message);
  }
};

  // Αν υπάρχει token από παλιό login, πήγαινε lobby κατευθείαν
  if (state.token) {
    showScreen("lobby");
    await refreshLobby();
  } else {
    showScreen("login");
  }
});
