<?php

/**
 * This file is part of the TelegramBot package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Entities;

/**
 * Class MessageEntity
 *
 * @link https://core.telegram.org/bots/api#messageentity
 *
 * @method string getType()     Type of the entity. Can be 'mention' (@username), 'hashtag' (#hashtag), 'cashtag' ($USD), 'bot_command' (/start@jobs_bot), 'url' (https://telegram.org), 'email' (do-not-reply@telegram.org), 'phone_number' (+1-212-555-0123), 'bold' (bold text), 'italic' (italic text), 'underline' (underlined text), 'strikethrough' (strikethrough text), 'code' (monowidth string), 'pre' (monowidth block), 'text_link' (for clickable text URLs), 'text_mention' (for users without usernames)
 * @method int    getOffset()   Offset in UTF-16 code units to the start of the entity
 * @method int    getLength()   Length of the entity in UTF-16 code units
 * @method string getUrl()      Optional. For "text_link" only, url that will be opened after user taps on the text
 * @method User   getUser()     Optional. For "text_mention" only, the mentioned user
 * @method string getLanguage() Optional. For "pre" only, the programming language of the entity text
 */
class MessageEntity extends Entity
{
    /**
     * {@inheritdoc}
     */
    protected function subEntities()
    {
        return [
            'user' => User::class,
        ];
    }
}
