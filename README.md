# ADISE25_ENGINEERS

# Ξερή – Web Card Game

## Table of Contents
=================
* [Εγκατάσταση](#εγκατάσταση)
  * [Απαιτήσεις](#απαιτήσεις)
  * [Οδηγίες Εγκατάστασης](#οδηγίες-εγκατάστασης)
* [Περιγραφή Παιχνιδιού](#περιγραφή-παιχνιδιού)
* [Περιγραφή API](#περιγραφή-api)
  * [Methods](#methods)
    * [Auth](#auth)
    * [Game](#game)
    * [Lobby](#lobby)
  * [Entities](#entities)
    * [Users](#users)
    * [Games](#games)
    * [Game_state](#game_state)
# Demo Page
Το παιχνίδι τρέχει τοπικά μέσω XAMPP.
Frontend:  
http://localhost/xeri/
,API:  
http://localhost/xeri/api.php
,DB:
http://localhost/phpmyadmin/index.php

# Εγκατάσταση
## Απαιτήσεις
* Apache2 (XAMPP)
* PHP 8+
* Web Browser
  
## Οδηγίες Εγκατάστασης

1. Αντιγράψτε τον φάκελο του project στον φάκελο του Apache:
   D:\xampp\htdocs\xeri\
2. Εκκινήστε Apache και MySQL από το XAMPP Control Panel.
3. Δημιουργήστε βάση δεδομένων MySQL (στο http://localhost/phpmyadmin/index.php) με όνομα
   xeri
4. Δημιουργήστε τους πίνακες users και games.
5. Ανοίξτε τον browser και επισκεφθείτε:
   http://localhost/xeri/
---------------------------------------------
# Περιγραφή Παιχνιδιού
Η **Ξερή** είναι ελληνικό παιχνίδι καρτών για δύο παίκτες με τράπουλα 52 φύλλων.
Στόχος του παιχνιδιού είναι η συγκέντρωση πόντων μέσω:
- συλλογής συγκεκριμένων φύλλων
- Ξερών
- Ξερών με Βαλέ
- πλειοψηφίας φύλλων στο τέλος του παιχνιδιού
Το παιχνίδι υποστηρίζει:
- Player vs Player
- Player vs CPU (υπολογιστής)
Η βάση δεδομένων αποθηκεύει μόνο την κατάσταση του παιχνιδιού, ενώ όλοι οι κανόνες υλοποιούνται στο backend API σε PHP.
-----------------------------------------------
## Περιγραφή API

# methods 
### Auth


#### Login χρήστη

POST /api.php?action=auth



**JSON body:**
```json
{
  "username": "nikos"
}
```
Επιστρέφει token authentication.

### game

Δημιουργία παιχνιδιού
POST /api.php?action=create_game

Δημιουργία παιχνιδιού με CPU
POST /api.php?action=create_game_cpu

Συμμετοχή σε παιχνίδι
POST /api.php?action=join_game

Ανάγνωση κατάστασης παιχνιδιού
POST /api.php?action=get_game_state

Παίξιμο κάρτας
POST /api.php?action=play_card

### Lobby

Λίστα διαθέσιμων παιχνιδιών
POST /api.php?action=list_waiting_games

Ακύρωση παιχνιδιού
POST /api.php?action=cancel_game


### Entities
*users
| Attribute  | Description            |
| ---------- | ---------------------- |
| id         | Μοναδικό ID χρήστη     |
| username   | Όνομα χρήστη           |
| token      | Token authentication   |
| created_at | Ημερομηνία δημιουργίας |



*games
| Attribute  | Description                  |
| ---------- | ---------------------------- |
| id         | ID παιχνιδιού                |
| player1_id | Παίκτης 1                    |
| player2_id | Παίκτης 2 ή CPU              |
| STATUS     | waiting / playing / finished |
| turn       | 1 ή 2                        |
| score_p1   | Σκορ παίκτη 1                |
| score_p2   | Σκορ παίκτη 2                |
| vs_cpu     | Παιχνίδι με υπολογιστή       |
| state      | JSON κατάσταση παιχνιδιού    |

### Game_state
```json
{
  "deck": [],
  "table": [],
  "hands": { "p1": [], "p2": [] },
  "captured": { "p1": [], "p2": [] },
  "xeri": { "p1": 0, "p2": 0 },
  "xeri_jack": { "p1": 0, "p2": 0 },
  "last_capturer": null
}
```

*NOTES:
Υποστηρίζεται multiplayer και CPU,
Πλήρες scoring στο τέλος του παιχνιδιού,
Όλοι οι κανόνες υλοποιούνται σε PHP
