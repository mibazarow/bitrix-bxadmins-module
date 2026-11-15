import Database from "better-sqlite3";
import type { ParsedFunction, ParsedClass, ParsedEvent, ParsedConstant, FunctionUsage } from "../utils/php-parser.js";
export declare function loadEmbeddingsCache(): void;
export declare function getSkippedEmbeddings(): number;
export declare function initDb(): void;
export declare function getDb(): Database.Database;
export declare function upsertModule(id: string, name: string, path: string, version?: string): void;
export declare function insertFunction(fn: ParsedFunction & {
    moduleId: string;
    filePath: string;
}): void;
export declare function insertClass(cls: ParsedClass & {
    moduleId: string;
    filePath: string;
}): void;
export declare function insertEvent(evt: ParsedEvent & {
    moduleId: string;
    filePath: string;
}): void;
export declare function insertClassConstant(c: ParsedConstant & {
    moduleId: string;
    filePath: string;
}): void;
export declare function insertFunctionUsage(u: FunctionUsage & {
    moduleId: string;
}): void;
export declare function clearModuleData(moduleId: string): void;
export declare function listModules(): {
    id: string;
    name: string;
    version: string;
}[];
export declare function listComponents(): {
    id: string;
    name: string;
    version: string;
    path: string;
}[];
export declare function getModuleFunctions(moduleId: string, limit: number, offset: number): {
    functions: any[];
    total: number;
};
export declare function getModuleEvents(moduleId: string): any[];
export declare function findFunction(name: string, moduleId?: string): any[];
export declare function findFunctionExact(name: string): any[];
export declare function findByNamespace(namespace: string): {
    classes: any[];
    functions: any[];
};
export declare function getClasses(moduleId: string, limit: number, offset: number): {
    classes: any[];
    total: number;
};
export declare function getFunctionCode(name: string, moduleId: string): string | null;
export declare function getClassCode(className: string, moduleId: string): string | null;
export declare function getClassMethods(className: string, moduleId: string): any[];
export declare function getClassConstants(className: string, moduleId: string): any[];
export declare function getClassConstantValue(className: string, moduleId: string, constantName: string): any;
export declare function getFunctionUsage(functionName: string, moduleId?: string): any[];
export declare function getModuleStructure(moduleId: string): any;
export declare function getClassHierarchy(className: string, moduleId: string): any;
export declare function findByInterface(interfaceName: string): any[];
export declare function getModuleDependencies(moduleId: string): any[];
export declare function getAllEventHandlers(): any[];
export declare function getDocResource(docPath: string): string | null;
export declare function insertDocChunk(filePath: string, chunkIndex: number, content: string, embedding: Buffer | null): void;
export declare function clearDocChunks(): void;
export declare function getDocChunkContents(): Map<string, {
    content: string;
    hasEmbedding: boolean;
}>;
export declare function replaceDocChunk(filePath: string, chunkIndex: number, content: string, embedding: Buffer | null): void;
export declare function deleteDocChunk(filePath: string, chunkIndex: number): void;
export declare function getMeta(key: string): string | null;
export declare function setMeta(key: string, value: string): void;
export declare function chunkKey(filePath: string, chunkIndex: number): string;
export declare function searchDocChunks(queryEmbedding: Float32Array, limit?: number): any[];
export declare function listDataManagers(moduleId?: string): any[];
export declare function getEntityFields(className: string, moduleId: string): any[];
export declare function getEventHandlers(eventName?: string): any[];
