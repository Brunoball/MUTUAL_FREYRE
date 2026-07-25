import { apiGet } from "../../_shared/httpClient";

export const getDocumentosStructure = () => apiGet("documentos");
