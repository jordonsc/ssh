<?php
namespace Bravo3\SSH;

use Bravo3\SSH\Enum\ShellType;
use Bravo3\SSH\Exceptions\NotAuthenticatedException;
use Bravo3\SSH\Exceptions\NotConnectedException;
use Eloquent\Enumeration\Exception\UndefinedMemberException;

/**
 * An interactive SSH shell for sending and receiving text data
 */
class Shell
{
    const READ_SIZE = 8192;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var mixed
     */
    protected $resource;

    /**
     * @var Terminal
     */
    protected $terminal;

    /**
     * This is a unique string that will include a timestamp, used to set the PS1 variable and aid the shell
     * in knowing when a command has completed execution
     *
     * @var string
     */
    protected $smart_marker = null;

    /**
     * @var ShellType
     */
    protected $shell_type;

    /**
     * On some systems, commands may echo the user and IP address behind an escape sequence. By default we will
     * detect and strip this out.
     *
     * @var bool
     */
    protected $strip_user_shadow = true;

    /**
     * Typically called from Connection::getShell()
     *
     * @param Connection $connection
     * @param Terminal   $terminal
     */
    function __construct(Connection $connection, Terminal $terminal)
    {
        if (!$connection->isConnected()) {
            throw new NotConnectedException();
        }

        if (!$connection->isAuthenticated()) {
            throw new NotAuthenticatedException();
        }

        $this->shell_type = null;
        $this->connection = $connection;
        $this->terminal   = $terminal;
        $this->resource   = ssh2_shell(
            $connection->getResource(),
            $terminal->getTerminalType(),
            $terminal->getEnv(),
            $terminal->getWidth(),
            $terminal->getHeight(),
            $terminal->getDimensionUnitType()
        );

    }

    /**
     * Read a given number of bytes - waiting until the count is matched
     *
     * @param int   $count   Number of bytes to read
     * @param float $timeout Time in seconds of no response to give up
     * @return string
     */
    public function readBytes($count, $timeout = 0.0)
    {
        $data  = '';
        $start = microtime(true);
        while (strlen($data) < $count) {
            $new = fread($this->resource, $count - strlen($data));
            if ($new) {
                $start = microtime(true);
                $data .= $new;
            }

            // Timeout - return the current buffer
            if ($timeout && (microtime(true) > ($start + $timeout))) {
                return $data;
            }
        }

        return $data;
    }

    /**
     * Read until a string marker is detected *anywhere in the response*
     *
     * @param string $marker
     * @param float  $timeout                Time in seconds before giving up waiting for new content to match
     * @param bool   $normalise_line_endings Convert CRLF to LF
     * @return string
     */
    public function readUntilMarker($marker, $timeout = 0.0, $normalise_line_endings = false)
    {
        $data  = '';
        $start = microtime(true);

        while ($timeout == 0 || (microtime(true) - $start < $timeout)) {
            $new = fread($this->resource, self::READ_SIZE);

            if ($new) {
                $data .= $new;
                $start = microtime(true);

                if ($normalise_line_endings) {
                    $data = str_replace("\r\n", "\n", $data);
                }

                $this->stripUserShadow($data);

                if (strpos($data, $marker) !== false) {
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Read until a string marker is detected *at the end of the output only*
     *
     * NB: This will not be matched if a single packet sends the marker and additional content,
     *     The marker must be at the end of a packet, eg. the PS1 marker waiting for a new command
     *
     * @param string $marker                 A marker to stop reading when found
     * @param float  $timeout                Time in seconds before giving up waiting for new content to match
     * @param bool   $normalise_line_endings Convert CRLF to LF
     * @return string
     */
    public function readUntilEndMarker($marker, $timeout = 0.0, $normalise_line_endings = false)
    {
        $data  = '';
        $start = microtime(true);

        while ($timeout == 0 || (microtime(true) - $start < $timeout)) {
            $new = fread($this->resource, self::READ_SIZE);

            if ($new) {
                $data .= $new;
                $start = microtime(true);

                if ($normalise_line_endings) {
                    $data = str_replace("\r\n", "\n", $data);
                }

                $this->stripUserShadow($data);

                if (substr($data, -strlen($marker)) == $marker) {
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Read until a regex is matched
     *
     * @param string $regex
     * @param float  $timeout                Time in seconds before giving up waiting for new content to match
     * @param bool   $normalise_line_endings Convert CRLF to LF
     * @return string
     */
    public function readUntilExpression($regex, $timeout = 0.0, $normalise_line_endings = false)
    {
        $data  = '';
        $start = microtime(true);

        while ($timeout == 0 || (microtime(true) - $start < $timeout)) {
            $new = fread($this->resource, self::READ_SIZE);

            if ($new) {
                $data .= $new;
                $start = microtime(true);

                if ($normalise_line_endings) {
                    $data = str_replace("\r\n", "\n", $data);
                }

                $this->stripUserShadow($data);

                if (preg_match_all($regex, $data) > 0) {
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * Keep reading until there is no new data for a specified length of time
     *
     * @param float $delay                  Time in seconds of no new content before returning
     * @param bool  $normalise_line_endings Convert CRLF to LF
     * @return string
     */
    public function readUntilPause($delay = 1.0, $normalise_line_endings = false)
    {
        $data  = '';
        $start = microtime(true);

        while (microtime(true) - $start < $delay) {
            $new = fread($this->resource, self::READ_SIZE);

            // If we have new data, reset the timer and append
            if ($new) {
                $data .= $new;
                $start = microtime(true);

                if ($normalise_line_endings) {
                    $data = str_replace("\r\n", "\n", $data);
                }

                $this->stripUserShadow($data);
            }
        }

        return $data;
    }

    /**
     * Wait for content to be sent and then read until there is a pause
     *
     * @param float $delay
     * @param bool  $normalise_line_endings
     * @return string
     */
    public function waitForContent($delay = 1.0, $timeout = 0.0, $normalise_line_endings = false)
    {
        return $this->readBytes(1, $timeout).$this->readUntilPause($delay, $normalise_line_endings);
    }

    /**
     * Send text to the server
     *
     * @param string $txt
     * @param bool   $newLine
     */
    public function send($txt, $newLine = false)
    {
        fwrite($this->resource, $newLine ? ($txt."\n") : $txt);
    }

    /**
     * Send a line to the server
     *
     * @param $txt
     */
    public function sendln($txt)
    {
        $this->send($txt, true);
    }

    /**
     * Set the PS1/prompt variable on the server so that smart commands will work
     *
     * @param string    $marker     Leave blank to use a random marker
     * @param ShellType $shell_type Will auto-detect is omitted
     * @param int       $timeout    Time in seconds before giving up waiting for a response
     */
    public function setSmartConsole($marker = null, ShellType $shell_type = null, $timeout = 15)
    {
        // Set the smart marker with a timestamp to keep it unique from any references, etc
        $this->smart_marker = $marker ? : '#:MKR#'.time().'$';

        if ($shell_type === null) {
            $shell_type = $this->getShellType();
        }

        switch ($shell_type) {
            // Bourne-shell compatibles
            default:
                $this->sendln('export PS1="'.$this->smart_marker.'"');
                break;
            // C-shell compatibles
            case ShellType::CSH():
            case ShellType::TCSH():
                $this->sendln('set prompt="'.$this->smart_marker.'"');
                break;
        }

        //$this->readUntilEndMarker($this->smart_marker, $timeout);
        $this->waitForContent(1, $timeout);
    }

    /**
     * Send a command to the server and receive it's response
     *
     * @param string $command
     * @param bool   $trim                   Trim the command echo and PS1 marker from the response
     * @param int    $timeout                Time in seconds to give up waiting for new content
     * @param bool   $normalise_line_endings Convert CRLF to LF
     * @return string
     */
    public function sendSmartCommand($command, $trim = true, $timeout = 0, $normalise_line_endings = false)
    {
        if ($this->getSmartMarker() === null) {
            $this->setSmartConsole();
        }

        $this->sendln($command);
        $response = $this->readUntilEndMarker($this->getSmartMarker(), $timeout, $normalise_line_endings);

        return $trim ? trim(substr($response, strlen($command) + 1, -strlen($this->getSmartMarker()))) : $response;
    }

    /**
     * Get the currently active smart marker
     *
     * @return string
     */
    public function getSmartMarker()
    {
        return $this->smart_marker;
    }

    /**
     * Get the connection resource, allowing you to read/write to it
     *
     * @return mixed
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Resolve the shell type
     *
     * @param float $timeout
     * @return ShellType
     */
    public function getShellType($timeout = 15.0)
    {
        if ($this->shell_type !== null) {
            return $this->shell_type;
        }

        $this->readyShell($timeout);
        $this->sendln("echo $0");

        $regex = '/echo \$0$\n^(\-[a-z]+)/sm';
        $out   = $this->readUntilExpression($regex, $timeout, true);

        $matches = null;
        preg_match_all($regex, $out, $matches);
        $shell_name = @$matches[1][0];

        // $0 over SSH may prefix with a hyphen
        if ($shell_name{0} == '-') {
            $shell_name = substr($shell_name, 1);
        }

        try {
            $this->shell_type = ShellType::memberByValue($shell_name);
        } catch (UndefinedMemberException $e) {
            $this->shell_type = ShellType::UNKNOWN();
        }

        return $this->shell_type;
    }

    /**
     * Tests the shell is responsive and waits for it to become idle, discarding anything in its buffer
     *
     * @param float $timeout
     * @return bool Returns true untill the timeout is hit waiting for a response from the shell
     */
    public function readyShell($timeout = 15.0)
    {
        $this->sendln("#");
        return (bool)$this->waitForContent(1, $timeout);
    }

    /**
     * On some systems, commands may echo the user and IP address behind an escape sequence. By default we will
     * detect and strip this out.
     *
     * @param boolean $strip_user_shadow
     * @return $this
     */
    public function setStripUserShadow($strip_user_shadow)
    {
        $this->strip_user_shadow = $strip_user_shadow;
        return $this;
    }

    /**
     * On some systems, commands may echo the user and IP address behind an escape sequence. By default we will
     * detect and strip this out.
     *
     * @return boolean
     */
    public function getStripUserShadow()
    {
        return $this->strip_user_shadow;
    }

    /**
     * Detect a user shadow in the text and strip it
     *
     * This function will just return if $this->strip_user_shadow is false.
     *
     * @param string $str
     */
    protected function stripUserShadow(&$str)
    {
        if (!$this->strip_user_shadow) {
            return;
        }

        if (strrpos($str, chr(7)) !== false) {
            // Bell detected do a regex check
            $match = null;
            if (preg_match('/(.*)\e]0;.*\a(.*)/s', $str, $match)) {
                $str = $match[1].$match[2];
            }
        }
    }

}
