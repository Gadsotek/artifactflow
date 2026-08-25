#define _GNU_SOURCE

#include <errno.h>
#include <linux/sched.h>
#include <seccomp.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/types.h>
#include <sys/wait.h>
#include <unistd.h>

static void fail(const char *message)
{
    fprintf(stderr, "artifactflow-process-deny: %s\n", message);
    exit(70);
}

static void require_rule(int result)
{
    if (result < 0) {
        fail("could not construct the process-creation syscall filter");
    }
}

static void install_filter(void)
{
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        fail("could not enable no-new-privileges");
    }

    scmp_filter_ctx filter = seccomp_init(SCMP_ACT_ALLOW);

    if (filter == NULL) {
        fail("could not initialize the process-creation syscall filter");
    }

    const uint32_t denied = SCMP_ACT_ERRNO(EPERM);

    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(fork), 0));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(vfork), 0));
    require_rule(seccomp_rule_add(
        filter,
        denied,
        SCMP_SYS(clone),
        1,
        SCMP_A0(SCMP_CMP_MASKED_EQ, CLONE_THREAD, 0)
    ));
    require_rule(seccomp_rule_add(filter, SCMP_ACT_ERRNO(ENOSYS), SCMP_SYS(clone3), 0));

    if (seccomp_load(filter) < 0) {
        seccomp_release(filter);
        fail("could not install the process-creation syscall filter");
    }

    seccomp_release(filter);
}

static void self_test_fork(void)
{
    errno = 0;
    const pid_t child = fork();
    const int error = errno;

    if (child == 0) {
        _exit(71);
    }

    if (child > 0) {
        waitpid(child, NULL, 0);
    }

    if (child != -1 || error != EPERM) {
        fail("process creation was not denied with EPERM");
    }
}

int main(int argc, char **argv)
{
    install_filter();

    if (argc == 2 && strcmp(argv[1], "--self-test") == 0) {
        self_test_fork();
        puts("ArtifactFlow PDF engine process creation deny active.");

        return 0;
    }

    if (argc < 2) {
        fail("expected --self-test or a command to execute");
    }

    execvp(argv[1], &argv[1]);
    fail("could not execute the protected command");
}
