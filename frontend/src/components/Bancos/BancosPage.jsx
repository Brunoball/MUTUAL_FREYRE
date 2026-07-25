import React from "react";
import ModuleScaffold from "../_shared/ModuleScaffold";
import { MODULE_CATALOG } from "../../config/moduleCatalog";

export default function BancosPage() {
  return <ModuleScaffold module={MODULE_CATALOG.bancos} />;
}
