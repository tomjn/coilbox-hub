<?php

namespace Tests\Unit\Replays;

use App\Domain\Replays\Script\StartScript;
use PHPUnit\Framework\TestCase;

class StartScriptTest extends TestCase
{
    public function test_it_reads_a_script_as_the_engine_does(): void
    {
        $script = StartScript::parse(<<<'TXT'
            // a comment
            [GAME]
            {
                MapName=Tumult;
                GameType=SplinterFaction 0.1.95;
                [PLAYER2] { Name=Ann; Team=1; Skill=(30.5); CountryCode=; }
                [player0] { name=Spec; spectator=1; team=0; }
                [TEAM1] { AllyTeam=3; Side=Fed; RgbColor=0.5 0.25 1; TeamLeader=2; }
                [ai4] { Name=Bot; ShortName=NullAI; Team=1; Host=2; Version=; }
                [allyteam3] { startrectleft=0.1; startrecttop=0.2; startrectright=0.3; startrectbottom=0.4; }
                [allyteam1] { startrectleft=0.5; startrecttop=0.5; startrectright=0.5; startrectbottom=0.9; }
                [modoptions] { MaxUnits=500; }
                stray;
            }
            TXT);

        $this->assertSame('Tumult', $script->map);
        $this->assertSame('SplinterFaction 0.1.95', $script->gameType);
        $this->assertSame([0, 2], array_keys($script->players));
        $ann = $script->players[2];
        $this->assertSame(['Ann', 1, 3, 'Fed', [0.5, 0.25, 1.0], 30.5, null], [$ann->name, $ann->team, $ann->allyTeam, $ann->side, $ann->colour, $ann->rating(), $ann->countryCode]);
        $this->assertTrue($script->players[0]->spectator);
        $this->assertNull($script->players[0]->team);
        $this->assertSame(['Bot', 'NullAI', null, 1, 3, 2], [$script->ais[4]->name, $script->ais[4]->shortName, $script->ais[4]->version, $script->ais[4]->team, $script->ais[4]->allyTeam, $script->ais[4]->host]);
        $this->assertSame(2, $script->teams[1]->leader);
        $this->assertSame(['left' => 0.1, 'top' => 0.2, 'right' => 0.3, 'bottom' => 0.4], $script->allyTeams[3]->startBox);
        $this->assertNull($script->allyTeams[1]->startBox);
        $this->assertSame(['maxunits' => '500'], $script->modOptions);
        $this->assertSame([], $script->mapOptions);
    }

    public function test_text_that_is_not_a_script_gives_an_empty_one(): void
    {
        $script = StartScript::parse("\0\xFF}}{[[==;;");
        $this->assertSame('', $script->map);
        $this->assertSame([], $script->players);
    }
}
