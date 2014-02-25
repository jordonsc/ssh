<?php
namespace SSH;

use NovaTek\SSH\Enum\TerminalType;
use NovaTek\SSH\Enum\TerminalUnit;
use NovaTek\SSH\Terminal;

class TerminalTest extends \PHPUnit_Framework_TestCase
{

    /**
     * @small
     */
    public function testProperties()
    {
        $terminal = new Terminal();
        $this->assertEquals(TerminalType::XTERM, $terminal->getTerminalType());
        $this->assertEquals(TerminalUnit::CHARACTERS, $terminal->getDimensionUnitType());
    }

}
 