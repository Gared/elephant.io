<?php

/**
 * This file is part of the Elephant.io package
 *
 * For the full copyright and license information, please view the LICENSE file
 * that was distributed with this source code.
 *
 * @copyright Wisembly
 * @license   http://www.opensource.org/licenses/MIT-License MIT License
 */

namespace ElephantIO;

/**
 * A collection of utilities.
 *
 * @author Toha <tohenk@yahoo.com>
 */
class Util
{
    /**
     * Truncate a long string message.
     *
     * @param string $message
     * @param integer $len
     * @return string
     */
    public static function truncate($message, $len = 100)
    {
        if ($message && strlen($message) > $len) {
            $message = sprintf('%s... %d more', substr($message, 0, $len), strlen($message) - $len);
        }

        return $message;
    }
}
