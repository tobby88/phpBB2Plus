<?php
/**
 * The original CrackerTracker emergency console required editing out an
 * unconditional die() and then exposed unauthenticated database mutations.
 * It is deliberately not activatable in this preserved build. Use the
 * token-protected DB Maintenance recovery console instead.
 */
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
exit('The legacy CrackerTracker emergency console is not available.');
