<?php

namespace App\Notifications;

use App\Models\MachineSshAccess;
use App\Models\User;
use App\Support\Machines\SshAccess;
use App\Support\Mail\BrandMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SshAccessNotice extends Notification
{
    use Queueable;

    /** @param  'requested'|'approved'|'declined'|'given'|'waiting_key'  $kind */
    public function __construct(
        public MachineSshAccess $access,
        public string $kind,
        public ?User $by = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $machine = $this->access->machine->name;
        $workspace = $this->access->workspace->name;
        $person = $this->access->personName();
        $by = $this->by->name ?? 'Someone';
        $login = $this->access->login;
        $address = $this->access->machine->ip_address;
        $command = filled($address) ? "ssh {$login}@{$address}" : null;

        [$subject, $heading, $paragraphs, $button] = match ($this->kind) {
            'requested' => [
                "{$person} asked for SSH access to {$machine}",
                "{$person} asked for SSH access",
                [
                    "{$person} wants to sign in to {$machine} in {$workspace} as {$login}, with the key {$this->access->fingerprint}.",
                    ...($this->access->note !== null ? ["Their note: {$this->access->note}"] : []),
                    'Approve it and ServerOS adds their key. Turn it down and nothing changes on the machine.',
                ],
                ['label' => 'Review the request', 'url' => SshAccess::reviewUrl($this->access)],
            ],
            'approved' => [
                "You have SSH access to {$machine}",
                'Your access was approved',
                [
                    "{$by} approved your SSH access to {$machine}. ServerOS is adding your key to the {$login} login.",
                    ...($command !== null ? ["Once it’s added, sign in with: {$command}"] : []),
                ],
                ['label' => 'See access', 'url' => SshAccess::tabUrl($this->access->machine)],
            ],
            'declined' => [
                "Your SSH access to {$machine} was turned down",
                'Your request was turned down',
                ["{$by} turned down your request for SSH access to {$machine}. Nothing changed on the machine."],
                ['label' => 'See access', 'url' => SshAccess::tabUrl($this->access->machine)],
            ],
            'waiting_key' => [
                "{$by} gave you SSH access to {$machine}",
                'Add your key to finish',
                ["{$by} gave you SSH access to {$machine} in {$workspace}, as {$login}. Add the public key from your computer and ServerOS puts it on the machine."],
                ['label' => 'Add your key', 'url' => SshAccess::tabUrl($this->access->machine)],
            ],
            default => [
                "{$by} gave you SSH access to {$machine}",
                'You have SSH access',
                [
                    "{$by} gave you SSH access to {$machine} in {$workspace}, as {$login}, with the key {$this->access->fingerprint}.",
                    ...($command !== null ? ["Once it’s added, sign in with: {$command}"] : []),
                ],
                ['label' => 'See access', 'url' => SshAccess::tabUrl($this->access->machine)],
            ],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->line($paragraphs[0])
            ->action($button['label'], $button['url']);

        return BrandMail::apply($mail, [
            'image' => 'invite',
            'heading' => $heading,
            'paragraphs' => $paragraphs,
            'button' => $button,
            'note' => $this->kind === 'requested'
                ? "You get this because you manage members of {$workspace}."
                : "You get this because you’re in {$workspace}.",
        ]);
    }
}
