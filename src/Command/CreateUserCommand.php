<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RememberMeRevoker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Creates (or re-passwords) an account. SIAB has no public registration, so this
 * is how CCM staff get access. The password is asked for interactively and hidden
 * so it never lands in the shell history.
 */
#[AsCommand(
    name: 'app:user:create',
    description: 'Crea una cuenta de acceso al panel (o cambia su contraseña).',
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
        private readonly RememberMeRevoker $rememberMe,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Correo institucional')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Nombre para mostrar')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Otorga ROLE_ADMIN')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Contraseña (si se omite, se pregunta)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));

        $password = $input->getOption('password');
        if ($password === null) {
            $question = (new Question('Contraseña: '))->setHidden(true)->setHiddenFallback(false);
            $password = $io->askQuestion($question);

            $confirm = (new Question('Confírmala: '))->setHidden(true)->setHiddenFallback(false);
            if ($password !== $io->askQuestion($confirm)) {
                $io->error('Las contraseñas no coinciden.');

                return Command::FAILURE;
            }
        }

        if (strlen((string) $password) < 8) {
            $io->error('La contraseña debe tener al menos 8 caracteres.');

            return Command::FAILURE;
        }

        $user = $this->users->findOneBy(['email' => $email]);
        $isNew = $user === null;

        if ($isNew) {
            $user = (new User())->setEmail($email);
        }

        if ($name = $input->getOption('name')) {
            $user->setDisplayName($name);
        }
        if ($input->getOption('admin')) {
            $user->setAdmin(true);
        }

        // Re-running the command on a deactivated account restores access, which is
        // the CLI escape hatch if every admin has been locked out of the UI.
        $user->setActive(true);

        $user->setPassword($this->hasher->hashPassword($user, (string) $password));

        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error(sprintf('%s: %s', $error->getPropertyPath(), $error->getMessage()));
            }

            return Command::FAILURE;
        }

        if ($isNew) {
            $this->em->persist($user);
        }
        $this->em->flush();

        if (!$isNew) {
            // Re-running this on an existing account sets a new password, so the
            // stored remember-me tokens must go with the old one. This is also the
            // recovery path when an account is suspected compromised.
            $this->rememberMe->revokeAll($user);
        }

        $io->success(sprintf(
            '%s %s (%s).',
            $isNew ? 'Cuenta creada:' : 'Contraseña actualizada:',
            $user->getEmail(),
            implode(', ', $user->getRoles()),
        ));

        return Command::SUCCESS;
    }
}
