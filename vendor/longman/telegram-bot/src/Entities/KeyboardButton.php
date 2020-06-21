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

use Longman\TelegramBot\Exception\TelegramException;

/**
 * Class KeyboardButton
 *
 * This object represents one button of the reply keyboard. For simple text buttons String can be used instead of this object to specify text of the button. Optional fields request_contact, request_location, and request_poll are mutually exclusive.
 *
 * @link https://core.telegram.org/bots/api#keyboardbutton
 *
 * @property bool                   $request_contact
 * @property bool                   $request_location
 * @property KeyboardButtonPollType $request_poll
 *
 * @method string                 getText()            Text of the button. If none of the optional fields are used, it will be sent to the bot as a message when the button is pressed
 * @method bool                   getRequestContact()  Optional. If True, the user's phone number will be sent as a contact when the button is pressed. Available in private chats only
 * @method bool                   getRequestLocation() Optional. If True, the user's current location will be sent when the button is pressed. Available in private chats only
 * @method KeyboardButtonPollType getRequestPoll() Optional. If specified, the user will be asked to create a poll and send it to the bot when the button is pressed. Available in private chats only
 *
 * @method $this setText(string $text)                                Text of the button. If none of the optional fields are used, it will be sent to the bot as a message when the button is pressed
 * @method $this setRequestContact(bool $request_contact)             Optional. If True, the user's phone number will be sent as a contact when the button is pressed. Available in private chats only
 * @method $this setRequestLocation(bool $request_location)           Optional. If True, the user's current location will be sent when the button is pressed. Available in private chats only
 * @method $this setRequestPoll(KeyboardButtonPollType $request_poll) Optional. If specified, the user will be asked to create a poll and send it to the bot when the button is pressed. Available in private chats only
 */
class KeyboardButton extends Entity
{
    /**
     * {@inheritdoc}
     */
    public function __construct($data)
    {
        if (is_string($data)) {
            $data = ['text' => $data];
        }
        parent::__construct($data);
    }

    /**
     * Check if the passed data array could be a KeyboardButton.
     *
     * @param array $data
     *
     * @return bool
     */
    public static function couldBe($data)
    {
        return is_array($data) && array_key_exists('text', $data);
    }

    /**
     * {@inheritdoc}
     */
    protected function validate()
    {
        if ($this->getProperty('text', '') === '') {
            throw new TelegramException('You must add some text to the button!');
        }

        // Make sure only 1 of the optional request fields is set.
        $field_count = array_filter([
            $this->getRequestContact(),
            $this->getRequestLocation(),
            $this->getRequestPoll(),
        ]);
        if (count($field_count) > 1) {
            throw new TelegramException('You must use only one of these fields: request_contact, request_location, request_poll!');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function __call($method, $args)
    {
        // Only 1 of these can be set, so clear the others when setting a new one.
        if (in_array($method, ['setRequestContact', 'setRequestLocation', 'setRequestPoll'], true)) {
            unset($this->request_contact, $this->request_location, $this->request_poll);
        }

        return parent::__call($method, $args);
    }
}
