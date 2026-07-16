#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

static int is_int_arg(const char *value) {
    size_t i;
    if (value == NULL || value[0] == '\0') return 0;
    for (i = 0; value[i] != '\0'; i++) {
        if (!isdigit((unsigned char)value[i])) return 0;
    }
    return 1;
}

static int in_range(const char *value, int min, int max) {
    int parsed;
    if (!is_int_arg(value)) return 0;
    parsed = atoi(value);
    return parsed >= min && parsed <= max;
}

int main(int argc, char **argv) {
    char *const clean_env[] = {
        "PATH=/usr/sbin:/usr/bin:/sbin:/bin",
        "HOME=/var/lib/bluecherry",
        "LANG=C",
        NULL
    };

    if (argc != 6) {
        fprintf(stderr, "usage: %s camera sensitivity noise samples frames\n", argv[0]);
        return 2;
    }

    if (!in_range(argv[1], 1, 9999) ||
        !in_range(argv[2], 1, 10) ||
        !in_range(argv[3], 0, 10) ||
        !in_range(argv[4], 1, 12) ||
        !in_range(argv[5], 2, 12)) {
        fprintf(stderr, "invalid argument\n");
        return 2;
    }

    char *const args[] = {
        "/usr/bin/python3",
        "/usr/local/sbin/bluecherry-motion-optimizer-web",
        "analyze",
        "--camera", argv[1],
        "--sensitivity", argv[2],
        "--noise-suppression", argv[3],
        "--samples", argv[4],
        "--frames-per-video", argv[5],
        "--work-dir", "/var/lib/bluecherry/motion-optimizer",
        "--stdout-json",
        NULL
    };

    execve(args[0], args, clean_env);
    perror("execve");
    return 127;
}

