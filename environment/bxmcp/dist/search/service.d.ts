export declare function embeddingsSignature(): string;
export declare function indexAllDocs(onProgress?: (current: number, total: number, embedded: number) => void): Promise<{
    total: number;
    embedded: number;
    skipped: number;
    removed: number;
}>;
export declare function searchDocs(query: string, limit?: number): Promise<any[]>;
