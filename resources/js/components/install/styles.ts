// The installer's form styles (Figma: 10 · Self-hosted install). Input and
// PasswordInput merge these over their own classes, so each one here
// replaces the default it clashes with.
export const label = 'text-[13px]/4 font-medium text-fg-primary';
export const field =
    'h-9 rounded-[9px] border-[0.5px] border-edge-strong bg-surface-field px-3 text-[13px] text-fg-primary shadow-none placeholder:text-fg-faint focus-visible:border-fg-faint focus-visible:ring-[0.5px] focus-visible:ring-fg-faint md:text-[13px] dark:bg-surface-field';
export const readonlyField =
    'bg-surface-field-readonly text-fg-secondary focus-visible:border-edge-strong focus-visible:ring-0 dark:bg-surface-field-readonly';
export const hint = 'text-[12.5px]/[18px] text-fg-muted';
export const divider = 'border-t-[0.5px] border-edge-tray-panel pt-5';
// A stack of check rows: each row draws its own top border.
export const rows = 'flex flex-col border-b-[0.5px] border-edge-tray-panel';
// The 36px buttons the installer's screens use (the kit's are 32px).
export const button = 'h-9';
export const ghostButton =
    'inline-flex h-9 shrink-0 cursor-pointer items-center justify-center rounded-[8px] px-3 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-secondary transition-colors hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50';
