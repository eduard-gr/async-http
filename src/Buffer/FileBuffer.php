<?php

namespace Eg\AsyncHttp\Buffer;

class FileBuffer implements BufferInterface
{
    /**
     * @var resource
     */
    private $reader = null;

    /**
     * @var resource
     */
    private $writer = null;

    private string|null $name = null;
    private int $size = 0;

    /**
     * @param string $directory
     */
    public function __construct(
        string $directory = null
    ){
        $this->name = tempnam($directory ?? sys_get_temp_dir(), "async-http-".bin2hex(random_bytes(64)));
    }

    public function __destruct()
    {
        if($this->writer){
            fclose($this->writer);
            $this->writer = null;
        }

        if($this->reader){
            fclose($this->reader);
            $this->reader = null;
        }

        if($this->name){
            unlink($this->name);
            $this->name = null;
        }
    }

    public function size():int
    {
        return $this->size - ftell($this->reader);
    }

    public function reset():void{
        $this->size = 0;

        if($this->writer){
            fclose($this->writer);
        }

        if($this->reader){
            fclose($this->reader);
        }

        $this->writer = fopen($this->name, "wb+");
        $this->reader = fopen($this->name, "rb");
    }

    public function append(string $fragment):void{
        $this->size += strlen($fragment);
        fwrite($this->writer, $fragment);
    }

    public function readLine():string|null{
        $index = ftell($this->reader) ?? 0;

        $line = stream_get_line($this->reader, 0);

        $needle_position = strpos($line, "\r\n");

        if($needle_position === false){
            fseek($this->reader, $index);
            return null;
        }

        fseek($this->reader, $index + $needle_position + 2);

        return substr($line, 0, $needle_position);
    }

    public function read(int $size):string|null
    {
        if($this->size() < $size){
            return null;
        }

        return stream_get_line($this->reader, $size);
    }
}