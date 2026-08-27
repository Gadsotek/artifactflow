#define _GNU_SOURCE

#include <arpa/inet.h>
#include <errno.h>
#include <linux/if_packet.h>
#include <linux/netlink.h>
#include <netinet/in.h>
#include <seccomp.h>
#include <stdbool.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/prctl.h>
#include <sys/socket.h>
#include <unistd.h>

static void fail(const char *message)
{
    fprintf(stderr, "artifactflow-network-deny: %s\n", message);
    exit(70);
}

static void require_rule(int result)
{
    if (result < 0) {
        fail("could not construct the outbound syscall filter");
    }
}

static void install_filter(void)
{
    if (prctl(PR_SET_NO_NEW_PRIVS, 1, 0, 0, 0) != 0) {
        fail("could not enable no-new-privileges");
    }

    scmp_filter_ctx filter = seccomp_init(SCMP_ACT_ALLOW);

    if (filter == NULL) {
        fail("could not initialize the outbound syscall filter");
    }

    const uint32_t denied = SCMP_ACT_ERRNO(EPERM);

    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(connect), 0));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(sendmsg), 0));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(sendmmsg), 0));
    require_rule(seccomp_rule_add(
        filter,
        denied,
        SCMP_SYS(sendto),
        1,
        SCMP_A4(SCMP_CMP_NE, 0)
    ));
    require_rule(seccomp_rule_add(
        filter,
        denied,
        SCMP_SYS(socket),
        1,
        SCMP_A0(SCMP_CMP_EQ, AF_PACKET)
    ));
    require_rule(seccomp_rule_add(
        filter,
        denied,
        SCMP_SYS(socket),
        1,
        SCMP_A0(SCMP_CMP_EQ, AF_NETLINK)
    ));
    require_rule(seccomp_rule_add(
        filter,
        denied,
        SCMP_SYS(socket),
        1,
        SCMP_A2(SCMP_CMP_EQ, IPPROTO_SCTP)
    ));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(io_uring_setup), 0));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(io_uring_enter), 0));
    require_rule(seccomp_rule_add(filter, denied, SCMP_SYS(io_uring_register), 0));

    if (seccomp_load(filter) < 0) {
        seccomp_release(filter);
        fail("could not install the outbound syscall filter");
    }

    seccomp_release(filter);
}

static void self_test_tcp(void)
{
    const int socket_fd = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, 0);

    if (socket_fd < 0) {
        fail("could not create the TCP self-test socket");
    }

    const struct sockaddr_in target = {
        .sin_family = AF_INET,
        .sin_port = htons(9),
        .sin_addr = {.s_addr = htonl(INADDR_LOOPBACK)},
    };

    errno = 0;
    const int result = connect(socket_fd, (const struct sockaddr *)&target, sizeof(target));
    const int error = errno;
    close(socket_fd);

    if (result != -1 || error != EPERM) {
        fail("TCP connect was not denied with EPERM");
    }
}

static void self_test_udp(void)
{
    const int socket_fd = socket(AF_INET, SOCK_DGRAM | SOCK_CLOEXEC, 0);

    if (socket_fd < 0) {
        fail("could not create the UDP self-test socket");
    }

    const struct sockaddr_in target = {
        .sin_family = AF_INET,
        .sin_port = htons(9),
        .sin_addr = {.s_addr = htonl(INADDR_LOOPBACK)},
    };
    const unsigned char payload = 0;

    errno = 0;
    const ssize_t result = sendto(
        socket_fd,
        &payload,
        sizeof(payload),
        0,
        (const struct sockaddr *)&target,
        sizeof(target)
    );
    const int error = errno;
    close(socket_fd);

    if (result != -1 || error != EPERM) {
        fail("UDP destination send was not denied with EPERM");
    }
}

static void self_test_sctp(void)
{
    errno = 0;
    const int socket_fd = socket(AF_INET, SOCK_STREAM | SOCK_CLOEXEC, IPPROTO_SCTP);
    const int error = errno;

    if (socket_fd >= 0) {
        close(socket_fd);
    }

    if (socket_fd != -1 || error != EPERM) {
        fail("SCTP socket was not denied with EPERM");
    }
}

int main(int argc, char **argv)
{
    install_filter();

    if (argc == 2 && strcmp(argv[1], "--self-test") == 0) {
        self_test_tcp();
        self_test_udp();
        self_test_sctp();
        puts("ArtifactFlow processor outbound syscall deny active.");

        return 0;
    }

    if (argc < 2) {
        fail("expected --self-test or a command to execute");
    }

    execvp(argv[1], &argv[1]);
    fail("could not execute the protected command");
}
