<?php

declare(strict_types=1);

namespace PhpServer\Exception;

/**
 * A frame is illegal, too large, or uses an unknown protocol version.
 *
 * When the text-rpc/1 error code is already known, pass it as `$code`
 * (for example -32600 or -32002). Use 0 when the code is not known yet.
 * The worker maps this exception to an ERR frame; it is not a reason to exit.
 *
 * @api
 */
final class ProtocolException extends \RuntimeException implements PhpServerException {}
