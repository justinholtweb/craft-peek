<?php

declare(strict_types=1);

namespace JustinHoltWeb\Peek\Mail;

use CraftCms\Cms\Email\Mailables\CraftMailable;
use CraftCms\Cms\Support\Html;
use Symfony\Component\Mime\Email;

use function CraftCms\Cms\t;

/**
 * "A scheduled release didn't publish." A {@see CraftMailable}, so the from address, reply-to and
 * mailer come from Craft's email settings. Use a fresh instance per recipient: `to()` appends.
 */
class ReleaseFailedMail extends CraftMailable
{
    public function __construct(
        public string $releaseName,
        public string $error,
        public string $url,
    ) {
        $this->subject = t('Release “{name}” wasn’t published', ['name' => $releaseName], category: 'peek');
    }

    public function build(): static
    {
        $intro = t('The scheduled release “{name}” wasn’t published. Nothing in it was applied: every draft is still in place.', ['name' => $this->releaseName], category: 'peek');
        $action = t('Open the release', category: 'peek');

        $html = '<p>'.Html::encode($intro).'</p>'
            .'<p><strong>'.Html::encode(t('Why', category: 'peek')).':</strong> '.Html::encode($this->error).'</p>'
            .'<p><a href="'.Html::encode($this->url).'">'.Html::encode($action).'</a></p>';

        $text = $intro."\n\n".t('Why', category: 'peek').': '.$this->error."\n\n".$action.': '.$this->url."\n";

        return $this->html($html)->withSymfonyMessage(fn (Email $message) => $message->text($text));
    }
}
