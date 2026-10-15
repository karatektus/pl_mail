<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Service\Mail\InboxProbeInterval;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * How often plMail asks Gmail and Outlook whether new mail has arrived, in
 * seconds.
 *
 * The bounds are InboxProbeInterval's, which clamps whatever it reads and throws
 * on whatever it is asked to save: a value this form accepts is one save() will
 * take. Range's own messages are the framework's, translated per locale, so
 * there is no wording of ours to keep in step.
 */
final class ProbeIntervalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('seconds', IntegerType::class, [
            'label'       => 'admin.address.probe.field.seconds',
            'help'        => 'admin.address.probe.field.seconds_help',
            'help_translation_parameters' => [
                '%min%' => InboxProbeInterval::MIN_SECONDS,
                '%max%' => InboxProbeInterval::MAX_SECONDS,
            ],
            'constraints' => [
                new NotBlank(),
                new Range(min: InboxProbeInterval::MIN_SECONDS, max: InboxProbeInterval::MAX_SECONDS),
            ],
            'attr'        => [
                'min'  => InboxProbeInterval::MIN_SECONDS,
                'max'  => InboxProbeInterval::MAX_SECONDS,
                'step' => 1,
            ],
        ]);
    }
}
