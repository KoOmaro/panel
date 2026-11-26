export const sampleAvatars = {
    maya: '/images/avatars/maya.png',
    sam: '/images/avatars/sam.png',
    leo: '/images/avatars/leo.png',
    ana: '/images/avatars/ana.png',
    kenji: '/images/avatars/kenji.png',
} as const;

export type SampleAvatar = keyof typeof sampleAvatars;
