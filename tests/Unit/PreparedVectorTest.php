<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Domain\Search\PreparedVector;
use ForumIntelligence\Domain\Search\Ranking;
final class PreparedVectorTest extends TestCase {
    public function testCompiledDirectionsPreserveStableNumericalResults(): void {
        $cases=[
            [[1e308,1e308],[-1e308,-1e308]],
            [[1e-300,1e-300],[1.0,1.0]],
            [[1e308,1e-308],[1e-308,1e308]],
            [[0.0,0.0],[1.0,1.0]],
            [['1.5','2.5'],[1.5,2.5]],
            [[PHP_FLOAT_MAX,-PHP_FLOAT_MAX],[PHP_FLOAT_MIN,PHP_FLOAT_MIN]],
            [array_map(static fn(int $i):float=>sin($i+.25),range(0,4095)),array_map(static fn(int $i):float=>cos($i+.75),range(0,4095))],
        ];
        foreach($cases as [$left,$right]){
            $json=json_encode($right,JSON_THROW_ON_ERROR);
            $frame=PreparedVector::encode($json);
            self::assertIsString($frame);
            self::assertSame(48+8*count($right),strlen($frame));
            $compiled=PreparedVector::fromFrame($frame,$json);
            self::assertInstanceOf(PreparedVector::class,$compiled);
            $query=PreparedVector::fromArray($left);
            self::assertInstanceOf(PreparedVector::class,$query);
            $canonical=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            self::assertEqualsWithDelta(Ranking::cosine($left,$canonical),$query->cosine($compiled),1e-12);
            self::assertSame($frame,PreparedVector::encode($json));
        }
        self::assertSame(0.0,PreparedVector::fromArray([1])->cosine(PreparedVector::fromArray([1,2])));
    }
    public function testFrameAdmissionRejectsDamageAndChangesToCanonicalJson(): void {
        $json='[0.25,0.75]';$frame=PreparedVector::encode($json);
        self::assertIsString($frame);
        $flip=$frame;$flip[20]=chr(ord($flip[20])^1);
        foreach(['',substr($frame,0,-1),$frame."\0",'BAD1'.substr($frame,4),$flip] as $invalid){
            self::assertNull(PreparedVector::fromFrame($invalid,$json));
        }
        foreach(['true','[null]','[1,0]','[0.25, 0.75]'] as $changed){
            self::assertNull(PreparedVector::fromFrame($frame,$changed),'A frame is bound to the exact canonical JSON bytes.');
        }
        $payload=substr($frame,0,-32);
        foreach([
            'FIV1'.pack('N',4097).substr($payload,8),
            substr($payload,0,8).pack('e',INF).substr($payload,16),
            substr($payload,0,8).pack('e',0.0).substr($payload,16),
            substr($payload,0,16).pack('e',2.0).substr($payload,24),
            substr($payload,0,16).pack('e',NAN).substr($payload,24),
        ] as $invalid){
            $signed=$invalid.hash('sha256',$json."\0".$invalid,true);
            self::assertNull(PreparedVector::fromFrame($signed,$json));
        }
    }
    public function testCompilationRejectsInvalidShapeWithoutUnsafeDeserialization(): void {
        foreach([[],['x'=>1.0],[1=>1.0],[INF],[NAN],[true],[null],array_fill(0,4097,1.0)] as $invalid){
            self::assertNull(PreparedVector::fromArray($invalid));
        }
        foreach(['true','null','[]','[null]','{"x":1}','[1e999]','not JSON',json_encode(array_fill(0,4097,1.0))] as $invalid){
            self::assertNull(PreparedVector::fromJson($invalid));
            self::assertNull(PreparedVector::encode($invalid));
        }
    }
}
