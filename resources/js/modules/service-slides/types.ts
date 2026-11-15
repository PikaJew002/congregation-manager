export type SlideKind = 'song' | 'liturgy' | 'other';

export type SlideKinds = Record<string, string>;

export type SlideAlias = {
    id: number;
    alias: string;
};

export type SlideSource = {
    id: number;
    title: string;
    kind: SlideKind;
    author: string | null;
    ccli: string | null;
    notes: string | null;
    slide_count: number | null;
    slide_lines: string[] | null;
    archived: boolean;
    last_used_on?: string | null;
    aliases?: SlideAlias[];
};

export type TemplateSlideRole = 'intro' | 'separator' | 'title';

export type TemplateSlide = {
    id: number;
    slide_index: number;
    label: string;
    role: TemplateSlideRole;
    missing: boolean;
};

export type PresetStep =
    | { type: 'template_slide'; slide_id: number }
    | {
          type: 'slot';
          key: string;
          label: string;
          kind: SlideKind | null;
          repeat: boolean;
          separator_slide_id: number | null;
          default_source_ids: number[];
      };

export type SlideTemplate = {
    id: number;
    name: string;
    default_kind: SlideKind | null;
    is_history_tracked: boolean;
    preset: PresetStep[] | null;
    steps?: PresetStep[];
    preset_saved?: boolean;
    decks_count?: number;
    slides: TemplateSlide[];
};

export type DeckStatus = 'draft' | 'stale' | 'generating' | 'ready' | 'failed';

export type DeckEntry = {
    id?: number;
    entry_type: 'template_slide' | 'source';
    slide_template_slide_id: number | null;
    slide_source_id: number | null;
    slot_key: string | null;
    auto: boolean;
    template_slide?: TemplateSlide | null;
    source?: Pick<
        SlideSource,
        'id' | 'title' | 'kind' | 'slide_count' | 'archived'
    > | null;
};

export type ServiceDeck = {
    id: number;
    name: string;
    position: number;
    status: DeckStatus;
    stale: boolean;
    blocked: boolean;
    generated_path: string | null;
    generated_at: string | null;
    warnings: string[] | null;
    slide_template_id: number;
    template: SlideTemplate;
    entries: DeckEntry[];
};

export type ServiceSummary = {
    id: number;
    title: string;
    service_date: string;
    decks: Array<{
        id: number;
        name: string;
        status: DeckStatus;
        stale: boolean;
        blocked: boolean;
        generated_at: string | null;
    }>;
};

export type ServiceDetail = {
    id: number;
    title: string;
    service_date: string;
    notes: string | null;
    decks: ServiceDeck[];
};

export type HistoryItem = {
    id: number;
    title: string;
    kind: SlideKind;
    archived: boolean;
    count: number;
    first_used: string;
    last_used: string;
};

export type HistoryService = {
    id: number;
    title: string;
    service_date: string;
    decks: Array<{ name: string; items: string[] }>;
};

export type AccessToken = {
    id: number;
    name: string;
    created_at: string;
    last_used_at: string | null;
};

export type LibrarySourceOption = Pick<SlideSource, 'id' | 'title' | 'kind'>;
