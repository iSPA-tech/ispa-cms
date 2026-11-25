import DecisionEditor from './DecisionEditor.vue'
import SetVariableEditor from './SetVariableEditor.vue'
import RetrieveEditor from './RetrieveEditor.vue'
import ChangeEditor from './ChangeEditor.vue'
import BlueprintCallableEditor from './BlueprintCallableEditor.vue'
import ClearVariableEditor from './ClearVariableEditor.vue'
import CreateObjectEditor from './CreateObjectEditor.vue'
import DeleteObjectEditor from './DeleteObjectEditor.vue'
import AssertConditionEditor from './AssertConditionEditor.vue'
import LogMessageEditor from './LogMessageEditor.vue'
import TerminateWorkflowEditor from './TerminateWorkflowEditor.vue'
import AggregateListEditor from './AggregateListEditor.vue'
import ListOperationEditor from './ListOperationEditor.vue'
import LoopEachEditor from './LoopEachEditor.vue'
import BreakLoopEditor from './BreakLoopEditor.vue'
import AgentActionEditor from './AgentActionEditor.vue'
import EmailSendEditor from './EmailSendEditor.vue'
import SchemaFormEditor from './SchemaFormEditor.vue'

/**
 * Map step_type to its editor component.
 * Types without a dedicated editor use the generic schema form (see resolveEditorComponent),
 * or the JSON viewer when the node catalog does not describe them.
 */
export const editorRegistry = {
    decision: DecisionEditor,
    condition: DecisionEditor,
    set_variable: SetVariableEditor,
    retrieve: RetrieveEditor,
    change: ChangeEditor,
    blueprint_callable: BlueprintCallableEditor,
    clear_variable: ClearVariableEditor,
    create_object: CreateObjectEditor,
    delete_object: DeleteObjectEditor,
    assert_condition: AssertConditionEditor,
    log_message: LogMessageEditor,
    terminate_workflow: TerminateWorkflowEditor,
    aggregate_list: AggregateListEditor,
    list_operation: ListOperationEditor,
    loop_each: LoopEachEditor,
    break_loop: BreakLoopEditor,
    ai_action: AgentActionEditor,
    // Typed text or a managed e-mail template.
    email_send: EmailSendEditor,
}

export const getEditorComponent = (stepType) => {
    if (!stepType) return null
    return editorRegistry[stepType] || editorRegistry[stepType?.toLowerCase?.()] || null
}

/** Dedicated editors by component name, for node definitions that name their editor (`editor: 'RetrieveEditor'`). */
const editorsByName = {
    DecisionEditor,
    SetVariableEditor,
    RetrieveEditor,
    ChangeEditor,
    BlueprintCallableEditor,
    ClearVariableEditor,
    CreateObjectEditor,
    DeleteObjectEditor,
    AssertConditionEditor,
    LogMessageEditor,
    TerminateWorkflowEditor,
    AggregateListEditor,
    ListOperationEditor,
    LoopEachEditor,
    BreakLoopEditor,
    AgentActionEditor,
    EmailSendEditor,
    SchemaFormEditor,
}

export const hasSchemaFields = (definition) => {
    const properties = definition?.config_schema?.properties
    return !!properties && typeof properties === 'object' && !Array.isArray(properties) && Object.keys(properties).length > 0
}

/**
 * Editor for a step: the dedicated component (named by the node definition's `editor`, or registered for the step
 * type), otherwise the generic schema form when the catalog definition describes settings, otherwise none
 * (the shell shows the JSON view).
 */
export const resolveEditorComponent = (stepType, definition = null) => {
    const named = definition?.editor ? editorsByName[definition.editor] || editorRegistry[definition.editor] : null
    return named || getEditorComponent(stepType) || (hasSchemaFields(definition) ? SchemaFormEditor : null)
}

export default editorRegistry
