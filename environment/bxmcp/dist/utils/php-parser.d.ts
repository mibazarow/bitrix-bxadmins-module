export interface ParsedFunction {
    name: string;
    fullName: string;
    lineNumber: number;
    phpdoc: string;
    code: string;
    args: {
        type: string;
        name: string;
        defaultValue?: string;
    }[];
    isMethod: boolean;
    className?: string;
    visibility: string;
    namespace: string;
}
export interface ParsedClass {
    name: string;
    fullName: string;
    lineNumber: number;
    type: "class" | "interface" | "trait";
    extends: string[];
    implements: string[];
    phpdoc: string;
    namespace: string;
}
export interface ParsedEvent {
    name: string;
    lineNumber: number;
    firedInFunction: string;
}
export interface ParsedConstant {
    name: string;
    fullName: string;
    lineNumber: number;
    value: string;
    namespace: string;
    className?: string;
    visibility?: string;
}
export interface FunctionUsage {
    functionName: string;
    filePath: string;
    lineNumber: number;
    moduleId: string;
    context: string;
}
interface ClassBlock {
    name: string;
    startLine: number;
    endLine: number;
}
export declare function parsePhpFunctions(phpCode: string, filePath: string, namespace: string, classBlocks: ClassBlock[]): ParsedFunction[];
export declare function parsePhpClasses(phpCode: string, filePath: string, namespace: string): ParsedClass[];
export declare function parsePhpConstants(phpCode: string): {
    name: string;
    lineNumber: number;
    value: string;
}[];
export declare function parsePhpEvents(phpCode: string): ParsedEvent[];
export declare function parsePhpConstantsFull(phpCode: string, namespace: string, classBlocks: ClassBlock[]): ParsedConstant[];
export declare function parseFunctionUsages(phpCode: string, filePath: string, moduleId: string): FunctionUsage[];
export declare function parseNamespace(content: string): string;
export declare function parseClassBlocksFromContent(content: string): ClassBlock[];
export {};
