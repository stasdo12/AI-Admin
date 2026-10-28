<?php
namespace OcMcp;

/**
 * A JSON-RPC level error (protocol error). Tool execution errors use ToolException instead.
 */
class RpcException extends \RuntimeException {
    /** @var int */
    private $httpStatus;
    /** @var mixed */
    private $data;

    public function __construct(int $code, string $message, int $httpStatus = 200, $data = null) {
        parent::__construct($message, $code);
        $this->httpStatus = $httpStatus;
        $this->data = $data;
    }

    public function httpStatus(): int {
        return $this->httpStatus;
    }

    public function data() {
        return $this->data;
    }
}
