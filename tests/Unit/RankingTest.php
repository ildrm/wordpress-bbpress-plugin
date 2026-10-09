<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Domain\Search\Ranking;
final class RankingTest extends TestCase {
    public function testPopularityCannotOverpowerRelevance(): void {
        self::assertGreaterThan(Ranking::hybrid(.1,0,true,99999),Ranking::hybrid(.9,0,false,0));
    }
    public function testCosineRejectsInvalidVectors(): void {
        self::assertSame(0.0,Ranking::cosine([1],[1,2]));
        self::assertSame(0.0,Ranking::cosine([0,0],[1,1]));
        self::assertSame(0.0,Ranking::cosine([INF],[1]));
        self::assertEqualsWithDelta(1,Ranking::cosine([2,1],[4,2]),0.000001);
    }

    public function testCosineRemainsStableAcrossFiniteScalesAndDirections(): void {
        foreach([1.0e308,1.0e-300,1.0,PHP_FLOAT_MAX,PHP_FLOAT_MIN] as $scale){
            self::assertEqualsWithDelta(1.0,Ranking::cosine([$scale,$scale],[$scale,$scale]),1e-12);
            self::assertEqualsWithDelta(-1.0,Ranking::cosine([$scale,$scale],[-$scale,-$scale]),1e-12);
            self::assertEqualsWithDelta(0.0,Ranking::cosine([$scale,$scale],[$scale,-$scale]),1e-12);
        }
        self::assertEqualsWithDelta(0.0,Ranking::cosine([1.0e308,1.0e-308],[1.0e-308,1.0e308]),1e-12);
        self::assertEqualsWithDelta(1.0,Ranking::cosine([1.0e-300,1.0e-300],[1.0,1.0]),1e-12);
        self::assertSame(0.0,Ranking::cosine([0.0,0.0],[1.0,1.0]));
    }
    public function testVectorValidationBoundsShapeAndFiniteNumericValues(): void {
        foreach([[],['x'=>1.0],[1=>1.0,2=>2.0],[1.0,NAN],[INF],['not-numeric'],[true],[null],array_fill(0,4097,1.0)] as $invalid){
            self::assertFalse(Ranking::validVector($invalid));
            self::assertSame(0.0,Ranking::cosine($invalid,$invalid));
        }
        self::assertTrue(Ranking::validVector(['1.5',2,1.0e308,1.0e-300]));
        self::assertTrue(Ranking::validVector(array_fill(0,4096,0.0)));
        self::assertEqualsWithDelta(1.0,Ranking::cosine(['1.5','2.5'],[1.5,2.5]),1e-12);
    }

    public function testGroundingRequiresStrongCoverageAndSolution(): void {
        self::assertFalse(Ranking::sufficient([['coverage'=>1,'solution'=>0]]));
        self::assertFalse(Ranking::sufficient([['coverage'=>.2,'solution'=>10]]));
        self::assertTrue(Ranking::sufficient([['coverage'=>.9,'solution'=>10]]));
    }
    public function testUnicodeSearchTokens(): void {
        self::assertContains('وردپرس',Ranking::tokens('وردپرس PHP php'));
        self::assertSame(1.0,Ranking::lexical('PHP caching','PHP caching guide',''));
    }
    public function testExplainedContributionsAreBoundedAndSumToTheActualScore(): void {
        $trace=Ranking::explain(.7,.8,true,4,'semantic');
        self::assertEqualsWithDelta(.65*.7,$trace['contributions']['lexical'],1e-12);
        self::assertEqualsWithDelta(.3*.8,$trace['contributions']['semantic'],1e-12);
        self::assertEqualsWithDelta(.01,$trace['contributions']['votes'],1e-12);
        self::assertSame(Ranking::hybrid(.7,.8,true,4),$trace['score']);
        self::assertSame(array_sum($trace['contributions']),$trace['score']);
        self::assertSame('semantic',$trace['retrieval']);
        self::assertSame(0.0,Ranking::hybrid(NAN,INF,false,-100));
        self::assertEqualsWithDelta(1.0,Ranking::hybrid(2,2,true,PHP_INT_MAX),1e-12);
        self::assertSame(0.0,Ranking::explain(0,0,false,-100)['contributions']['votes']);
        self::assertFalse(Ranking::sufficient([['coverage'=>.1,'solution'=>42,'score'=>1.0]]));
    }
}
