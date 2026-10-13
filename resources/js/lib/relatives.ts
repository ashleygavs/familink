import type { RelativeKind } from '@/types';

export type RelativeKindInfo = {
    kind: RelativeKind;
    /** Heading of the block, e.g. "Brother or sister". */
    title: string;
    /** How the person is described in a sentence, e.g. "brother or sister". */
    short: string;
    /** Label of the manual search box. */
    searchLabel: string;
};

/**
 * The kinds of registered relative a person can point to. Confirming one is
 * remembered on the record; parents are worked out from it when possible.
 */
export const RELATIVE_KINDS: RelativeKindInfo[] = [
    {
        kind: 'sibling',
        title: 'Brother or sister',
        short: 'brother or sister',
        searchLabel: 'Search for your brother or sister',
    },
    {
        kind: 'grandparent',
        title: 'Grandfather or grandmother',
        short: 'grandparent',
        searchLabel: 'Search for your grandfather or grandmother',
    },
    {
        kind: 'uncle_aunt',
        title: 'Uncle or aunt',
        short: 'uncle or aunt',
        searchLabel: 'Search for your uncle or aunt',
    },
    {
        kind: 'cousin',
        title: 'Cousin',
        short: 'cousin',
        searchLabel: 'Search for your cousin',
    },
    {
        kind: 'nephew_niece',
        title: 'Nephew or niece',
        short: 'nephew or niece',
        searchLabel: 'Search for your nephew or niece',
    },
    {
        kind: 'grandchild',
        title: 'Grandchild',
        short: 'grandchild',
        searchLabel: 'Search for your grandchild',
    },
];
