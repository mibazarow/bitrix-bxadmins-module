export type EmbeddingProfile = "docs_query" | "docs_passage" | "site_query" | "site_passage" | "code_query" | "generic";
export declare function getEmbedding(text: string, profile?: EmbeddingProfile): Promise<Float32Array | null>;
export declare function getEmbeddings(texts: string[], profile?: EmbeddingProfile): Promise<(Float32Array | null)[]>;
