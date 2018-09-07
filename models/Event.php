<?php

class Event
{
    public $name;

    public $season;
    public $number;
    public $format;

    public $start;
    public $kvalue;
    public $active;
    public $finalized;
    public $prereg_allowed;
    public $pkonly;
    public $threadurl;
    public $reporturl;
    public $metaurl;

    public $player_editdecks;

    // Class associations
    public $series; // belongs to Series
    public $host; // has one Player - host
    public $cohost; // has one Player - cohost

    // Subevents
    public $mainrounds;
    public $mainstruct;
    public $mainid; // Has one main subevent
    public $finalrounds;
    public $finalstruct;
    public $finalid; // Has one final subevent

    // Pairing/event related
    public $current_round;
    public $standing;
    public $player_reportable;
    public $player_reported_draws;
    public $prereg_cap; // Cap on player initiated registration
    public $late_entry_limit; // How many rounds we let people perform late entries

    public $private_decks; // Toggle to disable deck privacy for active events. Allows the metagame page to display during an active event and lets deck lists be viewed if disabled.
    public $private_finals; // As above, but for finals

    public $hastrophy;
    private $new;

    public function __construct($name)
    {
        if ($name == '') {
            $this->name = '';
            $this->mainrounds = '';
            $this->mainstruct = '';
            $this->finalrounds = '';
            $this->finalstruct = '';
            $this->host = null;
            $this->cohost = null;
            $this->threadurl = null;
            $this->reporturl = null;
            $this->metaurl = null;
            $this->start = null;
            $this->finalized = 0;
            $this->prereg_allowed = 0;
            $this->pkonly = 0;
            $this->hastrophy = 0;
            $this->new = true;
            $this->active = 0;
            $this->current_round = 0;
            $this->player_reportable = 0;
            $this->prereg_cap = 0;
            $this->player_editdecks = 1;
            $this->private_decks = 1;
            $this->private_finals = 1;
            $this->player_reported_draws = 0;
            $this->late_entry_limit = 0;

            return;
        }

        if (!$this->new) {
            $db = Database::getConnection();
            $stmt = $db->prepare('SELECT format, host, cohost, series, season, number,
                                   start, kvalue, finalized, prereg_allowed, pkonly, threadurl,
                                   metaurl, reporturl, active, current_round, player_reportable, player_editdecks,
                                    prereg_cap, private_decks, private_finals, player_reported_draws, late_entry_limit FROM events WHERE name = ?');
            if (!$stmt) {
                die($db->error);
            }
            $stmt->bind_param('s', $name);
            $stmt->execute();
            $stmt->bind_result($this->format, $this->host, $this->cohost, $this->series, $this->season, $this->number,
                         $this->start, $this->kvalue, $this->finalized, $this->prereg_allowed, $this->pkonly, $this->threadurl,
                          $this->metaurl, $this->reporturl, $this->active, $this->current_round, $this->player_reportable, $this->player_editdecks,
                          $this->prereg_cap, $this->private_decks, $this->private_finals, $this->player_reported_draws, $this->late_entry_limit);
            if ($stmt->fetch() == null) {
                throw new Exception('Event '.$name.' not found in DB');
            }
        }

        $stmt->close();

        $this->name = $name;
        $this->standing = new Standings($this->name, '0');

        // Main rounds
        $this->mainid = null;
        $this->mainrounds = '';
        $this->mainstruct = '';
        $stmt = $db->prepare('SELECT id, rounds, type FROM subevents
      WHERE parent = ? AND timing = 1');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($this->mainid, $this->mainrounds, $this->mainstruct);
        $stmt->fetch();
        $stmt->close();

        // Final rounds
        $this->finalid = null;
        $this->finalrounds = '';
        $this->finalstruct = '';
        $stmt = $db->prepare('SELECT id, rounds, type FROM subevents
      WHERE parent = ? AND timing = 2');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($this->finalid, $this->finalrounds, $this->finalstruct);
        $stmt->fetch();
        $stmt->close();

        // Trophy count
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM trophies WHERE event = ?');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($this->hastrophy);
        $stmt->fetch();
        $stmt->close();

        $this->new = false;
    }

    public static function session_timeout_stat()
    {
        // Get the current Session Timeout Value
        $currentTimeoutInSecs = ini_get('session.gc_maxlifetime');

        return $currentTimeoutInSecs;
    }

    public function save()
    {
        $db = Database::getConnection();

        if ($this->cohost == '') {
            $this->cohost = null;
        }
        if ($this->host == '') {
            $this->host = null;
        }
        if ($this->finalized) {
            $this->active = 0;
        }

        if ($this->new) {
            $stmt = $db->prepare('INSERT INTO events(name, start, format, host, cohost, kvalue,
                                               number, season, series, threadurl, reporturl,
                                               metaurl, prereg_allowed, finalized, pkonly, player_reportable,
                                               prereg_cap, player_editdecks, private_decks, private_finals, player_reported_draws, late_entry_limit)
                            VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sssssdddssssddddddddd', $this->name, $this->start, $this->format, $this->host, $this->cohost, $this->kvalue,
                                             $this->number, $this->season, $this->series, $this->threadurl, $this->reporturl,
                                             $this->metaurl, $this->prereg_allowed, $this->pkonly, $this->player_reportable,
                                             $this->prereg_cap, $this->player_editdecks, $this->private_decks, $this->private_finals, $this->player_reported_draws, $this->late_entry_limit);
            $stmt->execute() or die($stmt->error);
            $stmt->close();

            $this->newSubevent($this->mainrounds, 1, $this->mainstruct);
            $this->newSubevent($this->finalrounds, 2, $this->finalstruct);
        } else {
            $stmt = $db->prepare('UPDATE events SET
      start = ?, format = ?, host = ?, cohost = ?, kvalue = ?,
      number = ?, season = ?, series = ?, threadurl = ?, reporturl = ?,
      metaurl = ?, finalized = ?, prereg_allowed = ?, pkonly = ?, active = ?,
      current_round = ?, player_reportable = ?, prereg_cap = ?,
      player_editdecks = ?, private_decks = ?, private_finals = ?, player_reported_draws = ?, late_entry_limit = ?
      WHERE name = ?');
            $stmt or die($db->error);
            $stmt->bind_param('ssssdddssssdddddddddddds', $this->start, $this->format, $this->host, $this->cohost, $this->kvalue,
        $this->number, $this->season, $this->series, $this->threadurl, $this->reporturl,
        $this->metaurl, $this->finalized, $this->prereg_allowed, $this->pkonly, $this->active,
        $this->current_round, $this->player_reportable, $this->prereg_cap,
        $this->player_editdecks, $this->private_decks, $this->private_finals, $this->player_reported_draws, $this->late_entry_limit,
        $this->name);

            $stmt->execute() or die($stmt->error);
            $stmt->close();

            if ($this->mainid == null) {
                $this->newSubevent($this->mainrounds, 1, $this->mainstruct);
            } else {
                $main = new Subevent($this->mainid);
                $main->rounds = $this->mainrounds;
                $main->type = $this->mainstruct;
                $main->save();
            }

            if ($this->finalid == null) {
                $this->newSubevent($this->finalrounds, 2, $this->finalstruct);
            } else {
                $final = new Subevent($this->finalid);
                $final->rounds = $this->finalrounds;
                $final->type = $this->finalstruct;
                $final->save();
            }
        }
    }

    private function newSubevent($rounds, $timing, $type)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO subevents(parent, rounds, timing, type)
      VALUES(?, ?, ?, ?)');
        $stmt->bind_param('sdds', $this->name, $rounds, $timing, $type);
        $stmt->execute();
        $stmt->close();
    }

    public function getPlaceDeck($placing = '1st')
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT n.deck from entries n, events e
      WHERE e.name = n.event AND n.medal = ? AND e.name = ?');
        $stmt->bind_param('ss', $placing, $this->name);
        $stmt->execute();
        $stmt->bind_result($deckid);
        $result = $stmt->fetch();
        $stmt->close();
        if ($result == null) {
            $deck = null;
        } else {
            $deck = new Deck($deckid);
        }

        return $deck;
    }

    public function getPlacePlayer($placing = '1st')
    {
        $playername = Database::db_query_single('SELECT n.player from entries n, events e
                                             WHERE e.name = n.event
                                             AND n.medal = ?
                                             AND e.name = ?', 'ss', $placing, $this->name);

        return $playername;
    }

    public function getDecks()
    {
        $decks = [];
        $deckids = Database::list_result_single_param('SELECT deck FROM entries WHERE event = ? AND deck IS NOT NULL', 's', $this->name);

        foreach ($deckids as $deckid) {
            $decks[] = new Deck($deckid);
        }

        return $decks;
    }

    public function getFinalists()
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT medal, player, deck FROM entries
      WHERE event = ? AND medal != 'dot' ORDER BY medal, player");
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($medal, $player, $deck);

        $finalists = [];
        while ($stmt->fetch()) {
            $finalists[] = ['medal'       => $medal,
                           'player'       => $player,
                           'deck'         => $deck, ];
        }
        $stmt->close();

        return $finalists;
    }

    public function setFinalists($win, $sec, $t4 = null, $t8 = null)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE entries SET medal = 'dot' WHERE event = ?");
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->close();
        $stmt = $db->prepare('UPDATE entries SET medal = ? WHERE event = ? AND player = ?');
        $medal = '1st';
        $stmt->bind_param('sss', $medal, $this->name, $win);
        $stmt->execute();
        $medal = '2nd';
        $stmt->bind_param('sss', $medal, $this->name, $sec);
        $stmt->execute();
        if (!is_null($t4)) {
            $medal = 't4';
            $stmt->bind_param('sss', $medal, $this->name, $t4[0]);
            $stmt->execute();
            $stmt->bind_param('sss', $medal, $this->name, $t4[1]);
            $stmt->execute();
        }
        if (!is_null($t8)) {
            $medal = 't8';
            $stmt->bind_param('sss', $medal, $this->name, $t8[0]);
            $stmt->execute();
            $stmt->bind_param('sss', $medal, $this->name, $t8[1]);
            $stmt->execute();
            $stmt->bind_param('sss', $medal, $this->name, $t8[2]);
            $stmt->execute();
            $stmt->bind_param('sss', $medal, $this->name, $t8[3]);
            $stmt->execute();
        }
        $stmt->close();
    }

    public function getTrophyImageLink()
    {
        return "<a href=\"deck.php?mode=view&event={$this->name}\" class=\"borderless\">\n"
           .self::trophy_image_tag($this->name)."\n</a>\n";
    }

    public function isHost($name)
    {
        $ishost = strcasecmp($name, $this->host) == 0;
        $iscohost = strcasecmp($name, $this->cohost) == 0;

        return $ishost || $iscohost;
    }

    public function isFinalized()
    {
        return $this->finalized != 0;
    }

    public function isOrganizer($name)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT player FROM series_organizers WHERE series = ? and player = ?');
        $stmt->bind_param('ss', $this->series, $name);
        $stmt->execute();
        $stmt->bind_result($aname);
        while ($stmt->fetch()) {
            if (count($aname)) {
                $stmt->close();

                return true;
            }
        }
        $stmt->close();

        return false;
    }

    public function authCheck($playername)
    {
        $player = new Player($playername);

        if ($player->isSuper() ||
        $this->isHost($playername) ||
        $this->isOrganizer($playername)) {
            return true;
        }

        return false;
    }

    public function getPlayerCount()
    {
        return Database::single_result_single_param('SELECT count(*) FROM entries WHERE event = ?', 's', $this->name);
    }

    public function getPlayers()
    {
        return Database::list_result_single_param('SELECT player FROM entries WHERE event = ? ORDER BY medal, player', 's', $this->name);
    }

    public function getActiveRegisteredPlayers()
    {
        $players = $this->getPlayers();
        $registeredPlayers = [];

        foreach ($players as $player) {
            $entry = new Entry($this->name, $player);
            if (is_null($entry->deck)) {
                continue;
            }
            $standings = new Standings($this->name, $player);
            if ($entry->deck->isValid() && $standings->active) {
                $registeredPlayers[] = $player;
            }
        }

        return $registeredPlayers;
    }

    public function getRegisteredPlayers()
    {
        $players = $this->getPlayers();
        $registeredPlayers = [];

        foreach ($players as $player) {
            $entry = new Entry($this->name, $player);
            if (is_null($entry->deck)) {
                continue;
            }
            $standings = new Standings($this->name, $player);
            if ($entry->deck->isValid()) {
                $registeredPlayers[] = $player;
            }
        }

        return $registeredPlayers;
    }

    public function hasActivePlayer($playername)
    {
        $count = Database::db_query_single('SELECT COUNT(player) FROM standings WHERE event = ? AND player = ? AND active = 1', 'ss', $this->name, $playername);

        return $count == 1;
    }

    public function hasRegistrant($playername)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT count(player) FROM entries WHERE event = ? AND player = ?');
        $stmt->bind_param('ss', $this->name, $playername);
        $stmt->execute();
        $stmt->bind_result($isPlaying);
        $stmt->fetch();
        $stmt->close();

        return $isPlaying > 0;
    }

    public function getSubevents()
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id FROM subevents WHERE parent = ? ORDER BY timing');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($subeventid);

        $subids = [];
        while ($stmt->fetch()) {
            $subids[] = $subeventid;
        }
        $stmt->close();

        $subs = [];
        foreach ($subids as $subid) {
            $subs[] = new Subevent($subid);
        }

        return $subs;
    }

    public function getEntriesByDateTime()
    {
        return Database::list_result_single_param('SELECT player
                                                 FROM entries
                                                 WHERE event = ?
                                                 AND deck ORDER BY DATE(`registered_at`) ASC',
                                                 's', $this->name);
    }

    public function getEntriesByMedal()
    {
        return Database::list_result_single_param('SELECT player
                                             FROM entries
                                             WHERE event = ?
                                             AND deck ORDER BY medal, player ',
        's', $this->name);
    }

    public function getEntries()
    {
        $players = $this->getPlayers();

        $entries = [];
        foreach ($players as $player) {
            $entries[] = new Entry($this->name, $player);
        }

        return $entries;
    }

    public function getRegisteredEntries()
    {
        $players = $this->getPlayers();

        $entries = [];
        foreach ($players as $player) {
            $entry = new Entry($this->name, $player);
            if (is_null($entry->deck)) {
                continue;
            }
            if ($entry->deck->isValid()) {
                $entries[] = new Entry($this->name, $player);
            }
        }

        return $entries;
    }

    public function dropInvalidEntries()
    {
        $players = $this->getPlayers();
        $entries = [];
        foreach ($players as $player) {
            $entry = new Entry($this->name, $player);
            if (is_null($entry->deck) || !$entry->deck->isValid()) {
                $this->removeEntry($player);
            }
        }
    }

    public function removeEntry($playername)
    {
        $entry = new Entry($this->name, $playername);

        return $entry->removeEntry();
    }

    public function addPlayer($playername)
    {
        $playername = trim($playername);
        if (strcmp($playername, '') == 0) {
            return false;
        }
        $series = new Series($this->series);
        $playerIsBanned = $series->isPlayerBanned($playername);
        if ($playerIsBanned) {
            return false;
        }
        $entry = Entry::findByEventAndPlayer($this->name, $playername);
        $added = false;
        if (is_null($entry)) {
            $player = Player::findOrCreateByName($playername);
            $db = Database::getConnection();
            $stmt = $db->prepare('INSERT INTO entries(event, player, registered_at) VALUES(?, ?, NOW())');
            $stmt->bind_param('ss', $this->name, $player->name);
            if (!$stmt->execute()) {
                print_r($stmt->error);

                return false;
            }
            $stmt->close();
            //For late registration. Check to see if event is active, if so, create entry for player in standings
            if ($this->active == 1) {
                $standing = new Standings($this->name, $playername);
                $standing->save();
            }
            $added = true;
        }

        return $added;
    }

    public function dropPlayer($playername, $round = -1)
    {
        if ($round == -1) {
            $round = $this->current_round;
        }
        Database::db_query('UPDATE entries SET drop_round = ? WHERE event = ? AND player = ?', 'dss', $round, $this->name, $playername);
        Database::db_query('UPDATE standings SET active = 0 WHERE event = ? AND player = ?', 'ss', $this->name, $playername);
    }

    public function undropPlayer($playername)
    {
        Database::db_query('UPDATE entries SET drop_round = 0 WHERE event = ? AND player = ?', 'ss', $this->name, $playername);
        Database::db_query('UPDATE standings SET active = 1 WHERE event = ? AND player = ?', 'ss', $this->name, $playername);
    }

    public function getMatches()
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT m.id FROM matches m, subevents s, events e
      WHERE m.subevent = s.id AND s.parent = e.name AND e.name = ?
      ORDER BY s.timing, m.round');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->bind_result($matchid);

        $mids = [];
        while ($stmt->fetch()) {
            $mids[] = $matchid;
        }
        $stmt->close();

        $matches = [];
        foreach ($mids as $mid) {
            $matches[] = new Match($mid);
        }

        return $matches;
    }

    public function getRoundMatches($roundnum)
    {
        $db = Database::getConnection();
        if ($roundnum > $this->mainrounds) {
            $subevnum = 2;
            $roundnum = $roundnum - $this->mainrounds;
        } else {
            $subevnum = 1;
        }

        if ($roundnum == 'ALL') {
            $stmt = $db->prepare("SELECT m.id FROM matches m, subevents s, events e
        WHERE m.subevent = s.id AND s.parent = e.name AND e.name = ? AND
        s.timing = ? AND m.result <> 'P'");
            $stmt->bind_param('sd', $this->name, $subevnum);
        } else {
            $stmt = $db->prepare('SELECT m.id FROM matches m, subevents s, events e
        WHERE m.subevent = s.id AND s.parent = e.name AND e.name = ? AND
        s.timing = ? AND m.round = ?');
            $stmt->bind_param('sdd', $this->name, $subevnum, $roundnum);
        }

        $stmt->execute();
        $stmt->bind_result($matchid);

        $mids = [];
        while ($stmt->fetch()) {
            $mids[] = $matchid;
        }
        $stmt->close();

        $matches = [];
        foreach ($mids as $mid) {
            $matches[] = new Match($mid);
        }

        return $matches;
    }

    // In preparation for automating the pairings this function will add match the next pairing
    // results should be equal to 'P' for match in progress
    public function addPairing($playera, $playerb, $round, $result)
    {
        $id = $this->mainid;
        if ($result == 'BYE') {
            $verification = 'verified';
        } else {
            $verification = 'unverified';
        }

        if ($round > $this->mainrounds) {
            $id = $this->finalid;
            $round = $round - $this->mainrounds;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO matches(playera, playerb, round, subevent, result, verification) VALUES(?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssddss', $playera, $playerb, $round, $id, $result, $verification);
        $stmt->execute();
        $newmatch = $stmt->insert_id;
        $stmt->close();

        return $newmatch;
    }

    public function addMatch($playera, $playerb, $round = '99', $result = 'P', $playera_wins = '0', $playerb_wins = '0')
    {
        $draws = 0;
        $id = $this->mainid;

        if ($round > $this->mainrounds) {
            $id = $this->finalid;
            $round = $round - $this->mainrounds;
        }

        if ($round == 99) {
            $round = $this->current_round;
        }

        if ($result == 'BYE' or $result == 'D' or $result == 'League' or $playera_wins > 0 or $playerb_wins > 0) {
            $verification = 'verified';
        } else {
            $verification = 'unverified';
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('INSERT INTO matches(playera, playerb, round, subevent, result, playera_wins, playera_losses, playera_draws, playerb_wins, playerb_losses, playerb_draws, verification) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssddsdddddds', $playera, $playerb, $round, $id, $result, $playera_wins, $playerb_wins, $draws, $playerb_wins, $playera_wins, $draws, $verification); // draws have not been implemented yet so I just assign a zero for now
        $stmt->execute();
        $stmt->close();
    }

    // Assigns trophies based on the finals matches which are entered.
    public function assignTropiesFromMatches()
    {
        $t8 = [];
        $t4 = [];
        $sec = '';
        $win = '';
        if ($this->finalrounds > 0) {
            $quarter_finals = $this->finalrounds >= 3;
            if ($quarter_finals) {
                $quart_round = $this->mainrounds + $this->finalrounds - 2;
                $matches = $this->getRoundMatches($quart_round);
                foreach ($matches as $match) {
                    $t8[] = $match->getLoser();
                }
            }
            $semi_finals = $this->finalrounds >= 2;
            if ($semi_finals) {
                $semi_round = $this->mainrounds + $this->finalrounds - 1;
                $matches = $this->getRoundMatches($semi_round);
                foreach ($matches as $match) {
                    $t4[] = $match->getLoser();
                }
            }

            $finalmatches = $this->getRoundMatches($this->mainrounds + $this->finalrounds);
            $finalmatch = $finalmatches[0];
            $sec = $finalmatch->getLoser();
            $win = $finalmatch->getWinner();
        } else {
            $quarter_finals = $this->mainrounds >= 3;
            if ($quarter_finals) {
                $quart_round = $this->mainrounds - 2;
                $matches = $this->getRoundMatches($quart_round);
                foreach ($matches as $match) {
                    $t8[] = $match->getLoser();
                }
            }
            $semi_finals = $this->mainrounds >= 2;
            if ($semi_finals) {
                $semi_round = $this->mainrounds - 1;
                $matches = $this->getRoundMatches($semi_round);
                foreach ($matches as $match) {
                    $t4[] = $match->getLoser();
                }
            }

            $finalmatches = $this->getRoundMatches($this->mainrounds);
            $finalmatch = $finalmatches[0];
            $sec = $finalmatch->getLoser();
            $win = $finalmatch->getWinner();
        }
        $this->setFinalists($win, $sec, $t4, $t8);
    }

    public static function exists($name)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE name = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $stmt->store_result();
        $event_exists = $stmt->num_rows > 0;
        $stmt->close();

        return $event_exists;
    }

    public static function findMostRecentByHost($host_name)
    {
        // TODO: This should show the closest non-finalized event.
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE host = ? OR cohost = ? ORDER BY start DESC LIMIT 1');
        $stmt->bind_param('ss', $host_name, $host_name);
        $stmt->execute();
        $event_name = '';
        $stmt->bind_result($event_name);
        $event_exists = $stmt->fetch();
        $stmt->close();
        if ($event_exists) {
            return new self($event_name);
        }
    }

    public function findPrev()
    {
        if ($this->number == 0) {
            return;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE series = ? AND season = ? AND number = ? LIMIT 1');
        $num = $this->number - 1;
        $stmt->bind_param('sdd', $this->series, $this->season, $num);
        $stmt->execute();
        $stmt->bind_result($event_name);
        $exists = $stmt->fetch();
        $stmt->close();
        if ($exists) {
            return new self($event_name);
        }
    }

    public function findNext()
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE series = ? AND season = ? AND number = ? LIMIT 1');
        $num = $this->number + 1;
        $stmt->bind_param('sdd', $this->series, $this->season, $num);
        $stmt->execute();
        $stmt->bind_result($event_name);
        $exists = $stmt->fetch();
        $stmt->close();
        if ($exists) {
            return new self($event_name);
        }
    }

    public function makeLink($text)
    {
        return '<a href="event.php?name='.urlencode($this->name)."\">{$text}</a>";
    }

    public function linkTo()
    {
        return $this->makeLink($this->name);
    }

    public function linkReport()
    {
        return '<a href="eventreport.php?event='.urlencode($this->name)."\">{$this->name}</a>";
    }

    public static function count()
    {
        return Database::single_result('SELECT count(name) FROM events');
    }

    public static function largestEventNum()
    {
        return Database::single_result('SELECT max(number) FROM events where number != 128'); // 128 is "special"
    }

    public static function getOldest()
    {
        $eventname = Database::single_result('SELECT name FROM events ORDER BY start LIMIT 1');

        return new self($eventname);
    }

    public static function getNewest()
    {
        $eventname = Database::single_result('SELECT name FROM events ORDER BY start DESC LIMIT 1');

        return new self($eventname);
    }

    public static function getNextPreRegister($num = 20)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE prereg_allowed = 1 AND pkonly = 0 AND finalized = 0 AND DATE_SUB(start, INTERVAL 0 MINUTE) > NOW() ORDER BY start LIMIT ?');
        // 180 minute interal in Date_Sub is to compensate for time zone difference from Server and Eastern Standard Time which is what all events are quoted in
        $stmt->bind_param('d', $num);
        $stmt->execute();
        $stmt->bind_result($nextevent);
        $event_names = [];
        while ($stmt->fetch()) {
            $event_names[] = $nextevent;
        }
        $stmt->close();
        $events = [];
        foreach ($event_names as $eventname) {
            $events[] = new self($eventname);
        }

        return $events;
    }

    public static function getNextPKPreRegister($num = 4)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE prereg_allowed = 1 AND pkonly = 1 AND start > NOW() ORDER BY start LIMIT ?');
        $stmt->bind_param('d', $num);
        $stmt->execute();
        $stmt->bind_result($nextevent);
        $event_names = [];
        while ($stmt->fetch()) {
            $event_names[] = $nextevent;
        }
        $stmt->close();
        $events = [];
        foreach ($event_names as $eventname) {
            $events[] = new self($eventname);
        }

        return $events;
    }

    public function getSeasonPointAdjustment($player)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT adjustment, reason FROM season_points WHERE event = ? AND player = ?');
        $stmt or die($db->error);
        $stmt->bind_param('ss', $this->name, $player);
        $stmt->execute();
        $stmt->bind_result($adjustment, $reason);
        $exists = $stmt->fetch() != null;
        $stmt->close();
        if ($exists) {
            return ['adjustment' => $adjustment, 'reason' => $reason];
        } else {
            return;
        }
    }

    // Adjusts the season points for $player for this event by $points, with the reason $reason
    public function setSeasonPointAdjustment($player, $points, $reason)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT player FROM season_points WHERE event = ? AND player = ?');
        $stmt or die($db->error);
        $stmt->bind_param('ss', $this->name, $player);
        $stmt->execute();
        $exists = $stmt->fetch() != null;
        $stmt->close();
        if ($exists) {
            $stmt = $db->prepare('UPDATE season_points SET reason = ?, adjustment = ? WHERE event = ? AND player = ?');
            $stmt->bind_param('sdss', $reason, $points, $this->name, $player);
        } else {
            $stmt = $db->prepare('INSERT INTO season_points(series, season, event, player, adjustment, reason) values(?, ?, ?, ?, ?, ?)');
            $stmt->bind_param('sdssds', $this->series, $this->season, $this->name, $player, $points, $reason);
        }
        $stmt->execute();
        $stmt->close();

        return true;
    }

    public static function trophy_image_tag($eventname)
    {
        return "<img style=\"border-width: 0px\" src=\"displayTrophy.php?event={$eventname}\" />";
    }

    public function isLeague()
    {
        $test = $this->current_round;
        if ($test <= ($this->finalrounds + $this->mainrounds)) {
            if ($test >= $this->mainrounds) {
                $structure = $this->finalstruct;
            } else {
                $structure = $this->mainstruct;
            }

            return $structure == 'League';
        }

        return false;
    }

    // All this should probably go somewhere else
    // Pairs the round which is currently running.
    // This should probably be in Standings?
    public function pairCurrentRound()
    {
        //Check to see if we are main rounds or final, get structure
        $test = $this->current_round;
        if ($test < ($this->finalrounds + $this->mainrounds)) {
            if ($test >= $this->mainrounds) {
                // In the final rounds.
                $structure = $this->finalstruct;
                $subevent_id = $this->finalid;
                $round = 'final';
            } else {
                $structure = $this->mainstruct;
                $subevent_id = $this->mainid;
                $round = 'main';
            }
            // Run matching function
            switch ($structure) {
                case 'Swiss':
                    $this->swissPairing($subevent_id);
                    break;
                case 'Single Elimination':
                    $this->singleElimination($round);
                    break;
                case 'League':
                    //$this->current_round ++;
                    //$this->save();
                    break;
                case 'Round Robin':
                    //Do later
                    break;
            }
        } else {
            $this->active = 0;
            $this->finalized = 1;
            $this->save();
            $this->assignMedals();
            $ratings = new Ratings();
            $ratings->calcFinalizedEventRatings($this->name, $this->format, $this->start);
        }
        $this->current_round++;
        $this->save();

        return true;
    }

    // Pairs the current round by using the swiss method which is below.
    public function swissPairing($subevent_id)
    {
        Standings::resetMatched($this->name);

        // Invalid entries get a fake
        $players = $this->getPlayers();
        foreach ($players as $player) {
            $entry = new Entry($this->name, $player);
            if (is_null($entry->deck) || !$entry->deck->isValid()) {
                $playerStandings = new Standings($this->name, $player);
                $playerStandings->matched = 1;
                $playerStandings->save();
            }
        }

        // This section should really be replaced by an implementation of a stable roommates algorithm or something similar
        while (Standings::checkUnmatchedPlayers($this->name) > 0) {
            //echo "found an unmatched player";
            $player = $this->standing->getEventStandings($this->name, 1);
            $this->swissFindMatch($player, $subevent_id);
        }
    }

    public function swissFindMatch($player, $subevent)
    {
        $standing = new Standings($this->name, $player[0]->player);
        $opponents = $standing->getOpponents($this->name, $subevent, 1);
        $SQL_statement = "SELECT player FROM standings WHERE event = '".$this->name."' AND active = 1 AND matched = 0 AND player <> '".$player[0]->player."'";

        if ($opponents != null) {
            foreach ($opponents as $opponent) {
                $SQL_statement .= " AND player <> '".$opponent->player."'";
            }
        }
        $SQL_statement .= ' ORDER BY score desc, byes , RAND() LIMIT 1';

        $db = Database::getConnection();
        $stmt = $db->prepare($SQL_statement);
        $stmt or die($db->error);

        $stmt->execute() or die($stmt->error);
        $stmt->bind_result($playerb);
        if ($stmt->fetch() == null) { // No players left to match against, award bye
            $stmt->close();
            $this->award_bye($player[0]);
            $player[0]->matched = 1;
            $player[0]->save();
        } else {
            $stmt->close();
            $playerbStandings = new Standings($this->name, $playerb);
            $this->addPairing($player[0]->player, $playerb, ($this->current_round + 1), 'P');
            $player[0]->matched = 1;
            $player[0]->save();
            $playerbStandings->matched = 1;
            $playerbStandings->save();
        }
    }

    // I'm sure there is a proper algorithm to single or double elim with an arbitrary number of players
    // will look for one later, no need to reinvent the wheel. This works for now
    public function singleElimination($round)
    {
        if ($round == 'final') {
            if ($this->current_round == ($this->mainrounds)) {
                if ($this->finalrounds == 2) {
                    $this->top4Seeding();
                } elseif ($this->finalrounds == 3) {
                    $this->top8Seeding();
                } elseif ($this->finalrounds == 1) {
                    $this->top2Seeding();
                }
            } else {
                $top_cut = (($this->finalrounds - (($this->current_round) - $this->mainrounds)) * 2);
                $this->singleEliminationPairing($top_cut);
            }
        } elseif ($this->current_round == 0) {
            $this->singleEliminationByeCheck(2, 1);
        } else {
            $round = (($this->mainrounds - ($this->current_round)));
            $top_cut = pow(2, $round);
            $this->singleEliminationPairing($top_cut);
        }
    }

    public function singleEliminationPairing($top_cut)
    {
        $players = $this->standing->getEventStandings($this->name, 2);
        $players = array_slice($players, 0, $top_cut);
        $counter = 0;
        while ($counter < (count($players) - 1)) {
            $playera = $players[$counter]->player;
            if ($playera == null) {
                exit;
            }
            $counter++;
            $playerb = $players[$counter]->player;
            if ($playerb == null) {
                $counter--;
                $this->award_bye($players[$counter]);
                $counter++;
            } else {
                $this->addPairing($playera, $playerb, ($this->current_round + 1), 'P');
            }
            $counter++;
        }
    }

    public function singleEliminationByeCheck($check, $rounds)
    {
        $seedcounter = 1;
        $players = $this->standing->getEventStandings($this->name, 2);
        if (count($players) > $check) {
            $rounds++;
            $this->singleEliminationByeCheck(($check * 2), $rounds);
        } else {
            $byes_needed = $check - count($players);
            while ($byes_needed > 0) {
                $bye = rand(0, (count($players) - 1));
                $this->award_bye($players[$bye]);
                Standings::writeSeed($this->name, $players[$bye]->player, $seedcounter);
                $seedcounter++;
                unset($players[$bye]);
                $players = array_values($players);
                $byes_needed--;
            }

            $counter = 0;
            while ($counter < (count($players) - 1)) {
                $playera = $players[$counter]->player;
                $counter++;
                $playerb = $players[$counter]->player;
                $this->addPairing($playera, $playerb, ($this->current_round + 1), 'P');
                Standings::writeSeed($this->name, $playera, $seedcounter);
                $seedcounter++;
                Standings::writeSeed($this->name, $playerb, $seedcounter);
                $seedcounter++;
                $counter++;
            }
            if ($this->current_round >= $this->mainrounds) {
                $this->finalrounds = $rounds;
                $this->save();
            } else {
                $this->mainrounds = $rounds;
                $this->save();
            }
        }
    }

    // These functions need a serious DRYING out.  They are really obviously the same.
    // But we would need something in order to "order" the middle matches first.
    public function top2Seeding()
    {
        $players = $this->standing->getEventStandings($this->name, 3);
        $this->addPairing($players[0]->player, $players[1]->player, ($this->current_round + 1), 'P');
        Standings::writeSeed($this->name, $players[0]->player, 1);
        Standings::writeSeed($this->name, $players[1]->player, 2);
    }

    public function top4Seeding()
    {
        $players = $this->standing->getEventStandings($this->name, 3);
        if (count($players) < 4) {
            $this->top2Seeding();
        } else {
            $this->addPairing($players[0]->player, $players[3]->player, ($this->current_round + 1), 'P');
            $this->addPairing($players[1]->player, $players[2]->player, ($this->current_round + 1), 'P');
            Standings::writeSeed($this->name, $players[0]->player, 1);
            Standings::writeSeed($this->name, $players[1]->player, 3);
            Standings::writeSeed($this->name, $players[2]->player, 2);
            Standings::writeSeed($this->name, $players[3]->player, 4);
        }
    }

    public function top8Seeding()
    {
        $players = $this->standing->getEventStandings($this->name, 3);
        if (count($players) < 8) {
            $this->top4Seeding();
        } else {
            $this->addPairing($players[0]->player, $players[7]->player, ($this->current_round + 1), 'P');
            $this->addPairing($players[3]->player, $players[4]->player, ($this->current_round + 1), 'P');
            $this->addPairing($players[1]->player, $players[6]->player, ($this->current_round + 1), 'P');
            $this->addPairing($players[2]->player, $players[5]->player, ($this->current_round + 1), 'P');
            Standings::writeSeed($this->name, $players[0]->player, 1);
            Standings::writeSeed($this->name, $players[7]->player, 2);
            Standings::writeSeed($this->name, $players[3]->player, 3);
            Standings::writeSeed($this->name, $players[4]->player, 4);
            Standings::writeSeed($this->name, $players[1]->player, 5);
            Standings::writeSeed($this->name, $players[6]->player, 6);
            Standings::writeSeed($this->name, $players[2]->player, 7);
            Standings::writeSeed($this->name, $players[5]->player, 8);
        }
    }

    public function award_bye($player)
    {
        $this->addPairing($player->player, $player->player, ($this->current_round + 1), 'BYE');
    }

    public static function getActiveEvents()
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT name FROM events WHERE active = 1');
        $stmt->execute();
        $stmt->bind_result($nextevent);
        $event_names = [];
        while ($stmt->fetch()) {
            $event_names[] = $nextevent;
        }
        $stmt->close();

        $events = [];
        foreach ($event_names as $eventname) {
            $events[] = new self($eventname);
        }

        return $events;
    }

    public function resolveRound($subevent, $current_round)
    {
        if ($this->current_round <= $this->mainrounds) {
            $round = $this->current_round;
        } else {
            $round = ($this->current_round - $this->mainrounds);
        }
        $matches_remaining = Match::unresolvedMatchesCheck($subevent, $round);

        if ($matches_remaining > 0) {
            // Nothing to do yet
            //echo "There are still {$matches_remaining} unresolved matches";
            return 0;
        } else {
            if ($this->current_round > $this->mainrounds) {
                $structure = $this->finalstruct;
            } else {
                $structure = $this->mainstruct;
            }

            if ($this->current_round == $current_round) {
                $matches2 = $this->getRoundMatches($this->current_round);
                foreach ($matches2 as $match) {
                    //echo "about to update scores";
                    $match->updateScores($structure);
                }
                if ($structure == 'Swiss') {
                    $this->recalculateScores($structure);
                    Standings::updateStandings($this->name, $this->mainid, 1);
                } elseif ($structure == 'League') {
                    $this->recalculateScores('League');
                    Standings::updateStandings($this->name, $this->mainid, 1);
                }
                if ($structure != 'League' && $this->active === 1) {
                    $this->pairCurrentRound();
                }
            }
        }
    }

    public static function getEventBySubevent($subevent)
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT e.name FROM events e, subevents s
        WHERE s.parent = e.name AND s.id = ? LIMIT 1');
        $stmt->bind_param('s', $subevent);
        $stmt->execute();
        $stmt->bind_result($event);
        $stmt->fetch();
        $stmt->close();
        $event = new self($event);

        return $event;
    }

    public function recalculateScores($structure)
    {
        $this->resetScores();
        $matches2 = $this->getRoundMatches('ALL');
        foreach ($matches2 as $match) {
            //echo "about to update scores";
            $match->fixScores($structure);
        }
    }

    public function resetScores()
    {
        $standings = Standings::getEventStandings($this->name, 0);
        foreach ($standings as $standing) {
            $standing->score = 0;
            $standing->matches_played = 0;
            $standing->matches_won = 0;
            $standing->games_won = 0;
            $standing->byes = 0;
            $standing->games_played = 0;
            $standing->OP_Match = 0;
            $standing->PL_Game = 0;
            $standing->OP_Game = 0;
            $standing->draws = 0;

            $standing->save();
        }
    }

    public function resetEvent()
    {
        $db = Database::getConnection();

        $undropPlayer = $this->getPlayers();
        foreach ($undropPlayer as $player) {
            $this->undropPlayer($player);
        }

        $stmt = $db->prepare('DELETE FROM standings WHERE event = ?');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->close();

        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM ratings WHERE event = ?');
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->close();

        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM matches WHERE subevent = ? OR subevent = ?');
        $stmt->bind_param('ss', $this->mainid, $this->finalid);
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();

        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE entries SET medal = 'dot' WHERE event = ?");
        $stmt->bind_param('s', $this->name);
        $stmt->execute();
        $stmt->close();

        $this->current_round = 0;
        $this->active = 0;
        $this->save();
    }

    // This doesn't "repair" the round, it "re-pairs" the round by removing the pairings for the round.
    // It will always restart the top N if it is after the end rounds.
    public function repairRound()
    {
        if ($this->current_round <= ($this->mainrounds)) {
            $round = $this->current_round;
            $subevent = $this->mainid;
        } else {
            $round = $this->current_round - $this->mainrounds;
            $subevent = $this->finalid;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('DELETE FROM matches WHERE subevent = ? AND round = ?');
        $stmt->bind_param('dd', $subevent, $round);
        $stmt->execute();
        $removed = $stmt->affected_rows > 0;
        $stmt->close();

        $this->current_round--;
        $this->save();
        $this->recalculateScores('Swiss');
        Standings::updateStandings($this->name, $this->mainid, 1);
        $this->pairCurrentRound();
    }

    public function assignMedals()
    {
        if ($this->current_round > ($this->mainrounds)) {
            $structure = $this->finalstruct;
            $subevent_id = $this->finalid;
            $round = 'final';
        } else {
            $structure = $this->mainstruct;
            $subevent_id = $this->mainid;
            $round = 'main';
        }

        switch ($structure) {
       case 'Swiss':
           $this->AssignMedalsbyStandings();
           break;
       case 'Single Elimination':
           $this->assignTropiesFromMatches();
           break;
       case 'League':
           $this->AssignMedalsbyStandings();
           break;
       case 'Round Robin':
           //Do later
           break;
     }
    }

    public function AssignMedalsbyStandings()
    {
        $players = $this->standing->getEventStandings($this->name, 0);
        $numberOfPlayers = count($players);

        if ($numberOfPlayers < 8) {
            $medalCount = 2; // only give 2 medals if there are less than 8 players
        } elseif ($numberOfPlayers < 16) {
            $medalCount = 4; // only give 4 medals if there are less than 16 players
        } elseif ($numberOfPlayers >= 16) {
            $medalCount = 8;
        }

        $t8 = [];
        $t4 = [];

        switch ($medalCount) {
            case 8:
                $t8[3] = $players[7]->player;
            case 7:
                $t8[2] = $players[6]->player;
            case 6:
                $t8[1] = $players[5]->player;
            case 5:
                $t8[0] = $players[4]->player;
            case 4:
                $t4[1] = $players[3]->player;
            case 3:
                $t4[0] = $players[2]->player;
            case 2:
                $sec = $players[1]->player;
            case 1:
                $win = $players[0]->player;
        }
        $this->setFinalists($win, $sec, $t4, $t8);
    }

    public function is_full()
    {
        $entries = $this->getEntries();
        $players = count($entries);
        if ($this->prereg_cap == 0) {
            return false;
        } elseif ($this->prereg_cap > $players) {
            return false;
        } else {
            return true;
        }
    }

    public function matchesOfType($type)
    {
        $verification = '';
        if ($type == 'unfinished') {
            $verification = 'unverified';
        } elseif ($type == 'finished') {
            $verification = 'verified';
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT m.id FROM matches m, subevents s, events e
        WHERE m.subevent = s.id AND s.parent = e.name AND e.name = ? AND
        m.verification = ? AND m.round = ? AND s.timing = ? ORDER BY m.verification');
        $current_round = $this->current_round;
        $timing = 1;
        if ($current_round > $this->mainrounds) {
            $current_round -= $this->mainrounds;
            $timing = 2;
        }
        $stmt->bind_param('ssdd', $this->name, $verification, $current_round, $timing);
        $stmt->execute();
        $stmt->bind_result($matchid);

        $mids = [];
        while ($stmt->fetch()) {
            $mids[] = $matchid;
        }
        $stmt->close();

        $matches = [];
        foreach ($mids as $mid) {
            $matches[] = new Match($mid);
        }

        return $matches;
    }

    public function unfinishedMatches()
    {
        return $this->matchesOfType('unfinished');
    }

    public function finishedMatches()
    {
        return $this->matchesOfType('finished');
    }

    public function updateDecksFormat($format)
    {
        $deckIDs = Database::list_result_single_param('SELECT deck FROM entries WHERE event = ? AND deck IS NOT NULL', 's', $this->name);

        if (count($deckIDs)) {
            $db = Database::getConnection();
            foreach ($deckIDs as $deckID) {
                $stmt = $db->prepare('UPDATE decks SET format = ? WHERE id = ?');
                $stmt->bind_param('ss', $format, $deckID);
                $stmt->execute();
            }
            $stmt->close();
        }
    }

    public function startEvent()
    {
        $entries = $this->getRegisteredEntries();
        Standings::startEvent($entries, $this->name);
        $this->dropInvalidEntries();
        $this->pairCurrentRound();
        $this->active = 1;
        $this->save();
    }
}
