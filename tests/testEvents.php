<?php
require('lib.php');

// declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EventsTest extends TestCase
{
    public function testSeriesCreation() {

        if (!Series::exists("Test")) {
            $series = new Series("");
            $series->name = "Test";
            $series->active = true; 
            $series->start_time = "00:00" . ":00";
            $series->start_day = "Friday";
            $series->prereg_default = true;
            $series->pkonly_default = false;
            $series->save();
        }
        
        $series = new Series("Test");
        $this->assertEquals($series->name, "Test");
        return $series;
    }

    /**
     * @depends testSeriesCreation
     */
     public function testEventCreation($series) {
        $recentEvents = $series->getRecentEvents(1);
        if (count($recentEvents) == 0)  {
            $number = 1;
        }
        else {
            $event = $recentEvents[0];
            do {
                $number = $event->number + 1;
                $event = $event->findNext();
            } while ($event != null);
        }
        $name = sprintf("%s %d.%02d", $series->name, 1, $number);
        
        $event = new Event("");
        $event->start = date('Y-m-d H:00:00');
        $event->name = $name;

        $event->format = "Modern";
        $event->host = NULL;
        $event->cohost = NULL;
        $event->kvalue = 16;
        $event->series = $series->name;
        $event->season = 1;
        $event->number = $number;
        $event->threadurl = "";
        $event->metaurl = "";
        $event->reporturl = "";

        $event->prereg_allowed = 1;
        $event->pkonly = 0;
        $event->player_reportable = 1;

        $event->mainrounds = 3;
        $event->mainstruct = "Swiss";
        $event->finalrounds = 3;
        $event->finalstruct = "Single Elimination";
        $event->save();

        $event = new Event($name);
        $this->assertEquals($event->name, $name);
        $this->assertEquals($event->start, date('Y-m-d H:00:00'));
        return $event;
    }

    /**
     * @depends testEventCreation
     */
    public function testRegistration($event) {
        for ($i=0; $i < 8; $i++) {
            $event->addPlayer("testplayer". $i);
        }
        // 8 players have expressed interest in the event.
        $this->assertEquals(count($event->getEntries()), 8);
        // No players have filled out decklists.
        $this->assertEquals(count($event->getRegisteredEntries()), 0);
        
        $deck = insertDeck("testplayer0", $event->name, "60 Plains", "");
        $this->assertEmpty($deck->errors);
        $deck = insertDeck("testplayer1", $event->name, "60 Island", "");
        $this->assertEmpty($deck->errors);
        $deck = insertDeck("testplayer2", $event->name, "40 Swamp", "");
        $this->assertNotEmpty($deck->errors);
        $deck = insertDeck("testplayer3", $event->name, "60 Swamp\n100 Relentless Rats", "15 Swamp");
        $this->assertEmpty($deck->errors);
        $deck = insertDeck("testplayer4", $event->name, "20 Mountain\n20 Forest\n\n\n\n\n\n\n\n\n\n\n\n4 Plains\n4 Plains\n4 Plains\n4 Plains\n4 Plains\n\n\n", "");
        $this->assertEmpty($deck->errors);
        $this->assertEquals(count($event->getRegisteredEntries()), 4);
        return $event;
    }
}

function insertDeck($player, $eventName, $main, $side) {
    $deck = new Deck(0);
    $deck->playername = $player;
    $deck->eventname = $eventName;
    $deck->maindeck_cards = parseCardsWithQuantity($main);
    $deck->sideboard_cards = parseCardsWithQuantity($side);
    $deck->save();
    return $deck;
}
?>