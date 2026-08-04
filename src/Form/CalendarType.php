<?php

declare(strict_types=1);

namespace App\Form;

use App\Domain\Helper\TimezoneHelper;
use App\Entity\Calendar\Calendar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Create or rename a calendar: the three things about one a person chooses.
 *
 * Not on the form, deliberately, and each for its own reason. Visibility and
 * which calendar is the default are one-click toggles in the list rather than
 * fields behind a Save, because both are things you flip while looking at the
 * consequence. Role and account are not the user's to set — they say what
 * provisioned a calendar, and a form that let you turn an account's calendar
 * into a custom one would be a form that orphans everything extracted from that
 * account's mail.
 */
final class CalendarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'calendar.form.name',
                'constraints' => [
                    new NotBlank(message: 'calendar.name_required'),
                    // Matches the column. Without it a long name is a database
                    // error on flush rather than a message next to the field.
                    new Length(max: 120),
                ],
            ])
            // Expanded, and rendered as swatches by _calendar_color_widget in
            // the modal form theme — the same argument LabelType makes for its
            // colour field: nobody can pick a colour from a list of words.
            ->add('color', ChoiceType::class, [
                'label'       => 'calendar.form.color',
                'choices'     => array_combine(Calendar::COLORS, Calendar::COLORS),
                'expanded'    => true,
                'choice_attr' => static fn (string $value): array => ['data-swatch' => $value],
                // Every choice is its own label, so translating them would send
                // "#2563eb" through the catalogue and log a miss per swatch.
                'choice_translation_domain' => false,
            ])
            ->add('timeZone', ChoiceType::class, [
                'label'   => 'calendar.form.time_zone',
                'choices' => $this->zoneChoices(),
                // The zone an event with none of its own is read in, which is
                // not obvious from a field labelled "Time zone" sitting under
                // a name.
                'help'    => 'calendar.form.time_zone_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Calendar::class,
        ]);
    }

    /**
     * IANA identifiers, grouped by region into optgroups — the same shape and
     * the same source as the display-timezone picker in settings.
     *
     * @return array<string, array<string, string>>
     */
    private function zoneChoices(): array
    {
        $choices = [];

        foreach (TimezoneHelper::grouped() as $region => $identifiers) {
            $choices[$region] = array_combine($identifiers, $identifiers);
        }

        return $choices;
    }
}
